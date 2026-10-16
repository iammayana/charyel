<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CompetitiveXpAccountingService
{
    public const DAILY_COMPETITIVE_CAP = 400;

    /**
     * Build the canonical principal key for a user or guest.
     *
     * @throws InvalidArgumentException
     */
    public static function buildPrincipalKey(?int $userId, ?int $guestIdentityId): string
    {
        if ($userId !== null && $guestIdentityId === null) {
            if ($userId <= 0) {
                throw new InvalidArgumentException("User ID must be a positive integer.");
            }
            return 'U:' . $userId;
        }

        if ($guestIdentityId !== null && $userId === null) {
            if ($guestIdentityId <= 0) {
                throw new InvalidArgumentException("Guest identity ID must be a positive integer.");
            }
            return 'G:' . $guestIdentityId;
        }

        throw new InvalidArgumentException("Exactly one of userId or guestIdentityId must be provided.");
    }

    /**
     * Atomically awards competitive and lifetime XP under strict 400 XP daily cap and idempotency guarantees.
     *
     * @return array{
     *     principal_key: string,
     *     award_date: string,
     *     requested_xp: int,
     *     competitive_xp_awarded: int,
     *     daily_total_after_award: int,
     *     remaining_daily_headroom: int,
     *     is_capped: bool,
     *     is_duplicate: bool
     * }
     */
    public function awardCompetitiveXp(
        ?int $userId,
        ?int $guestIdentityId,
        int $requestedXp,
        string $rewardSource,
        string $sourceId,
        string $eventId,
        ?CarbonInterface $receivedAt = null
    ): array {
        if ($requestedXp <= 0) {
            throw new InvalidArgumentException("Requested XP must be strictly greater than 0.");
        }

        $allowedSources = [
            'quiz_completion',
            'daily_streak',
            'church_bonus',
            'guest_claim_transfer',
            'late_sync_rollover',
            'reversal',
        ];

        if (!in_array($rewardSource, $allowedSources, true)) {
            throw new InvalidArgumentException("Invalid reward source: {$rewardSource}");
        }

        $principalKey = self::buildPrincipalKey($userId, $guestIdentityId);

        // Normalize server receipt date to UTC YYYY-MM-DD (Authoritative Server Time)
        $utcNow = ($receivedAt ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');
        $awardDate = $utcNow->toDateString();

        // Authoritative payload hash for idempotent verification and conflict detection
        $incomingPayloadHash = hash('sha256', json_encode([
            'principal_key' => $principalKey,
            'requested_xp' => $requestedXp,
            'reward_source' => $rewardSource,
            'source_id' => $sourceId,
        ]));

        return DB::transaction(function () use (
            $userId,
            $guestIdentityId,
            $principalKey,
            $requestedXp,
            $rewardSource,
            $sourceId,
            $eventId,
            $awardDate,
            $utcNow,
            $incomingPayloadHash
        ) {
            // 1. Idempotency Check: Processed Events
            $existingEvent = DB::table('processed_events')
                ->where('event_id', $eventId)
                ->first();

            if ($existingEvent !== null) {
                // Verify payload integrity: same event_id with different payload must be rejected as an explicit conflict
                if ($existingEvent->payload_hash !== $incomingPayloadHash) {
                    throw new InvalidArgumentException(
                        "Idempotency conflict: event_id '{$eventId}' was already processed with a conflicting payload."
                    );
                }

                // Legitimate identical replay: retrieve current counter state without re-awarding
                $currentCounter = DB::table('user_daily_competitive_counters')
                    ->where('principal_key', $principalKey)
                    ->where('award_date', $awardDate)
                    ->first();

                $total = $currentCounter ? (int) $currentCounter->competitive_xp_total : 0;
                $headroom = max(0, self::DAILY_COMPETITIVE_CAP - $total);

                return [
                    'principal_key' => $principalKey,
                    'award_date' => $awardDate,
                    'requested_xp' => $requestedXp,
                    'competitive_xp_awarded' => 0,
                    'daily_total_after_award' => $total,
                    'remaining_daily_headroom' => $headroom,
                    'is_capped' => false,
                    'is_duplicate' => true,
                ];
            }

            // 2. Idempotency Check: Unique Ledger Check
            $existingLedger = DB::table('user_xp_ledger')
                ->where('principal_key', $principalKey)
                ->where('reward_source', $rewardSource)
                ->where('source_id', $sourceId)
                ->first();

            if ($existingLedger !== null) {
                if ($existingLedger->event_id !== $eventId) {
                    throw new InvalidArgumentException(
                        "Conflict: reward source '{$rewardSource}' with source_id '{$sourceId}' has already been awarded to principal '{$principalKey}' under event '{$existingLedger->event_id}'."
                    );
                }

                $currentCounter = DB::table('user_daily_competitive_counters')
                    ->where('principal_key', $principalKey)
                    ->where('award_date', $awardDate)
                    ->first();

                $total = $currentCounter ? (int) $currentCounter->competitive_xp_total : 0;
                $headroom = max(0, self::DAILY_COMPETITIVE_CAP - $total);

                return [
                    'principal_key' => $principalKey,
                    'award_date' => $awardDate,
                    'requested_xp' => $requestedXp,
                    'competitive_xp_awarded' => 0,
                    'daily_total_after_award' => $total,
                    'remaining_daily_headroom' => $headroom,
                    'is_capped' => false,
                    'is_duplicate' => true,
                ];
            }

            // 3. Atomically upsert and acquire exclusive pessimistic row lock on the daily counter
            $counter = $this->getOrCreateDailyCounterLocked($principalKey, $userId, $guestIdentityId, $awardDate, $utcNow);

            $currentCompetitiveTotal = (int) $counter->competitive_xp_total;
            $headroom = max(0, self::DAILY_COMPETITIVE_CAP - $currentCompetitiveTotal);
            $competitiveAwarded = min($requestedXp, $headroom);
            $newCompetitiveTotal = $currentCompetitiveTotal + $competitiveAwarded;
            $isCapped = ($requestedXp > $competitiveAwarded);

            // 4. Update the daily counter
            DB::table('user_daily_competitive_counters')
                ->where('id', $counter->id)
                ->update([
                    'competitive_xp_total' => $newCompetitiveTotal,
                    'last_event_id' => $eventId,
                    'updated_at' => $utcNow,
                ]);

            // 5. Append to user_xp_ledger (lifetime XP audit trail)
            DB::table('user_xp_ledger')->insert([
                'user_id' => $userId,
                'guest_identity_id' => $guestIdentityId,
                'reward_source' => $rewardSource,
                'source_id' => $sourceId,
                'event_id' => $eventId,
                'xp_amount' => $requestedXp,
                'awarded_at' => $utcNow,
            ]);

            // 6. Update lifetime user_profiles balance if registered user
            if ($userId !== null) {
                DB::table('user_profiles')
                    ->where('user_id', $userId)
                    ->increment('total_xp', $requestedXp);
            }

            // 7. Mark event processed for replay protection and event-level auditability
            DB::table('processed_events')->insert([
                'event_id' => $eventId,
                'session_id' => ($rewardSource === 'quiz_completion' && Str::isUuid($sourceId)) ? $sourceId : null,
                'device_id' => 'accounting_service',
                'user_id' => $userId,
                'guest_identity_id' => $guestIdentityId,
                'event_type' => 'competitive_xp_award',
                'payload_hash' => $incomingPayloadHash,
                'status' => 'accepted',
                'http_status_code' => 200,
                'response_payload' => json_encode([
                    'event_id' => $eventId,
                    'principal_key' => $principalKey,
                    'user_id' => $userId,
                    'guest_identity_id' => $guestIdentityId,
                    'award_date' => $awardDate,
                    'reward_source' => $rewardSource,
                    'source_id' => $sourceId,
                    'requested_xp' => $requestedXp,
                    'competitive_xp_awarded' => $competitiveAwarded,
                    'daily_total_after_award' => $newCompetitiveTotal,
                ]),
                'processed_at' => $utcNow,
            ]);

            return [
                'principal_key' => $principalKey,
                'award_date' => $awardDate,
                'requested_xp' => $requestedXp,
                'competitive_xp_awarded' => $competitiveAwarded,
                'daily_total_after_award' => $newCompetitiveTotal,
                'remaining_daily_headroom' => self::DAILY_COMPETITIVE_CAP - $newCompetitiveTotal,
                'is_capped' => $isCapped,
                'is_duplicate' => false,
            ];
        }, 3);
    }

    /**
     * Atomically ensures the daily counter row exists and locks it for the active transaction.
     */
    protected function getOrCreateDailyCounterLocked(
        string $principalKey,
        ?int $userId,
        ?int $guestIdentityId,
        string $awardDate,
        CarbonInterface $utcNow
    ): object {
        // Step 1: Check if the counter row already exists without locking (avoids InnoDB gap locks)
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', $principalKey)
            ->where('award_date', $awardDate)
            ->first();

        // Step 2: If row does not exist, insert initial record.
        // Strictly catch UniqueConstraintViolationException only, allowing genuine database errors to surface.
        if ($counter === null) {
            try {
                DB::table('user_daily_competitive_counters')->insert([
                    'principal_key' => $principalKey,
                    'user_id' => $userId,
                    'guest_identity_id' => $guestIdentityId,
                    'award_date' => $awardDate,
                    'competitive_xp_total' => 0,
                    'created_at' => $utcNow,
                    'updated_at' => $utcNow,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Row was created concurrently by peer connection; proceed to lock the existing row
            }
        }

        // Step 3: Row is guaranteed to exist; acquire exclusive row lock (record lock, not gap lock)
        return DB::table('user_daily_competitive_counters')
            ->where('principal_key', $principalKey)
            ->where('award_date', $awardDate)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Reconstruct the daily competitive total and audit trail from immutable event records.
     *
     * @return array{
     *     counter_total: int,
     *     reconstructed_competitive_total: int,
     *     reconstructed_gross_total: int,
     *     is_reconciled: bool,
     *     events: array<int, object>
     * }
     */
    public function reconstructDailyAudit(string $principalKey, string $awardDate): array
    {
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', $principalKey)
            ->where('award_date', $awardDate)
            ->first();

        $counterTotal = $counter ? (int) $counter->competitive_xp_total : 0;

        // Query immutable processed_events joined with user_xp_ledger
        $events = DB::table('processed_events as pe')
            ->join('user_xp_ledger as uxl', 'pe.event_id', '=', 'uxl.event_id')
            ->where('pe.event_type', 'competitive_xp_award')
            ->where('uxl.principal_key', $principalKey)
            ->where('pe.response_payload->award_date', $awardDate)
            ->select([
                'pe.event_id',
                'uxl.principal_key',
                'uxl.user_id',
                'uxl.guest_identity_id',
                'uxl.reward_source',
                'uxl.source_id',
                'uxl.xp_amount as gross_lifetime_xp',
                'pe.response_payload',
                'pe.processed_at',
            ])
            ->orderBy('pe.processed_at', 'asc')
            ->get();

        $reconstructedCompetitive = 0;
        $reconstructedGross = 0;
        $eventList = [];

        foreach ($events as $event) {
            $payload = is_string($event->response_payload)
                ? json_decode($event->response_payload, true)
                : (array) $event->response_payload;

            $competitiveAwarded = (int) ($payload['competitive_xp_awarded'] ?? 0);
            $event->competitive_xp_awarded = $competitiveAwarded;

            $reconstructedCompetitive += $competitiveAwarded;
            $reconstructedGross += (int) $event->gross_lifetime_xp;
            $eventList[] = $event;
        }

        return [
            'counter_total' => $counterTotal,
            'reconstructed_competitive_total' => $reconstructedCompetitive,
            'reconstructed_gross_total' => $reconstructedGross,
            'is_reconciled' => ($counterTotal === $reconstructedCompetitive),
            'events' => $eventList,
        ];
    }
}
