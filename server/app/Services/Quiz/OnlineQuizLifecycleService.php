<?php

namespace App\Services\Quiz;

use App\Exceptions\OnlineQuizException;
use App\Exceptions\ScoringValidationException;
use App\Models\ActiveOnlineQuizSession;
use App\Models\GuestIdentity;
use App\Services\CompetitiveXpAccountingService;
use App\Services\Quiz\AuthoritativeScoringService;
use App\Services\Quiz\QuestionBankService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnlineQuizLifecycleService
{
    public const MAX_TRIAL_QUIZZES = 3;
    public const MAX_TRIAL_GROSS_XP = 150;
    public const DEFAULT_SESSION_DURATION_MINUTES = 30;

    public function __construct(
        protected QuestionBankService $questionBankService,
        protected AuthoritativeScoringService $scoringService,
        protected CompetitiveXpAccountingService $accountingService
    ) {}

    /**
     * Start a new online quiz session for an authenticated guest.
     * Enforces trial limits, single active session constraint, and persists server-owned question manifest.
     *
     * @param GuestIdentity $guest Authenticated guest principal
     * @param array{
     *     quiz_mode?: string,
     *     category?: string|null,
     *     difficulty?: string,
     *     testament?: string|null,
     *     device_id?: string,
     *     question_count?: int
     * } $options
     * @return array Client-safe session metadata and questions
     * @throws OnlineQuizException
     */
    public function startSession(GuestIdentity $guest, array $options = []): array
    {
        $quizMode = $options['quiz_mode'] ?? ActiveOnlineQuizSession::QUIZ_MODE_STANDARD;
        $difficulty = $options['difficulty'] ?? ActiveOnlineQuizSession::DIFFICULTY_MIXED;
        $category = $options['category'] ?? null;
        $testament = $options['testament'] ?? null;
        $deviceId = $options['device_id'] ?? 'device_unknown';
        $questionCount = max(1, (int) ($options['question_count'] ?? 10));

        if (!in_array($quizMode, ActiveOnlineQuizSession::ALLOWED_QUIZ_MODES, true)) {
            $quizMode = ActiveOnlineQuizSession::QUIZ_MODE_STANDARD;
        }

        if (!in_array($difficulty, ActiveOnlineQuizSession::ALLOWED_DIFFICULTIES, true)) {
            $difficulty = ActiveOnlineQuizSession::DIFFICULTY_MIXED;
        }

        return DB::transaction(function () use (
            $guest,
            $quizMode,
            $difficulty,
            $category,
            $testament,
            $deviceId,
            $questionCount
        ) {
            // 1. Pessimistically lock guest identity row
            /** @var GuestIdentity $lockedGuest */
            $lockedGuest = GuestIdentity::where('id', $guest->id)->lockForUpdate()->firstOrFail();

            // 2. Enforce contact and claim invariant
            if (!$lockedGuest->hasContact()) {
                throw new OnlineQuizException(
                    "Contact details (email or phone) are required before starting a quiz.",
                    OnlineQuizException::CODE_CONTACT_REQUIRED,
                    403
                );
            }

            if ($lockedGuest->isClaimed()) {
                throw new OnlineQuizException(
                    "Guest identity has been claimed and cannot participate as an anonymous guest.",
                    OnlineQuizException::CODE_SESSION_FORBIDDEN,
                    403
                );
            }

            // 3. Expire any stale active session for this guest
            $this->sweepStaleActiveSessionLocked($lockedGuest);

            // 4. Invariant: Prevent overlapping active sessions
            $existingActive = ActiveOnlineQuizSession::where('guest_identity_id', $lockedGuest->id)
                ->where('status', ActiveOnlineQuizSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existingActive !== null) {
                throw new OnlineQuizException(
                    "An active online quiz session already exists for this guest.",
                    OnlineQuizException::CODE_ACTIVE_SESSION_EXISTS,
                    409,
                    ['active_session_id' => $existingActive->id]
                );
            }

            // 5. Enforce trial limits
            if ($lockedGuest->completed_quiz_count >= self::MAX_TRIAL_QUIZZES) {
                throw new OnlineQuizException(
                    "Trial quiz limit reached (maximum " . self::MAX_TRIAL_QUIZZES . " quizzes).",
                    OnlineQuizException::CODE_TRIAL_QUIZ_LIMIT_REACHED,
                    403
                );
            }

            if ($lockedGuest->gross_lifetime_xp >= self::MAX_TRIAL_GROSS_XP) {
                throw new OnlineQuizException(
                    "Trial gross XP limit reached (maximum " . self::MAX_TRIAL_GROSS_XP . " XP).",
                    OnlineQuizException::CODE_TRIAL_XP_LIMIT_REACHED,
                    403
                );
            }

            // Shared trial capacity accounting: count unexpired offline authorizations
            $unexpiredOffline = DB::table('offline_quiz_authorizations')
                ->where('guest_identity_id', $lockedGuest->id)
                ->where('status', 'issued')
                ->where('expires_at', '>', now())
                ->count();

            $totalAllocated = $lockedGuest->completed_quiz_count + $unexpiredOffline;
            if ($totalAllocated >= self::MAX_TRIAL_QUIZZES) {
                throw new OnlineQuizException(
                    "Trial opportunity capacity exhausted across online and offline reservations.",
                    OnlineQuizException::CODE_TRIAL_CAPACITY_EXHAUSTED,
                    403
                );
            }

            // 6. Draw questions from question bank
            try {
                $manifest = $this->questionBankService->selectQuestions($questionCount, [
                    'category' => $category,
                    'difficulty' => ($difficulty !== ActiveOnlineQuizSession::DIFFICULTY_MIXED ? $difficulty : null),
                    'testament' => $testament,
                    'quiz_mode' => $quizMode,
                ]);
            } catch (\RuntimeException $e) {
                throw new OnlineQuizException(
                    $e->getMessage(),
                    OnlineQuizException::CODE_INSUFFICIENT_QUESTIONS,
                    422
                );
            }

            // 7. Persist server-owned ordered manifest and hash
            $manifestPayload = [
                'manifest_id' => $manifest->manifestId,
                'quiz_mode' => $manifest->quizMode,
                'difficulty' => $manifest->difficulty,
                'category' => $manifest->category,
                'items' => array_map(fn($item) => $item->toArray(), $manifest->items),
            ];
            $manifestHash = hash('sha256', json_encode($manifestPayload));

            $sessionId = (string) Str::uuid7();
            $now = now();
            $expiresAt = $now->copy()->addMinutes(self::DEFAULT_SESSION_DURATION_MINUTES);

            ActiveOnlineQuizSession::create([
                'id' => $sessionId,
                'guest_identity_id' => $lockedGuest->id,
                'quiz_mode' => $quizMode,
                'category' => $category,
                'difficulty' => $difficulty,
                'device_id' => $deviceId,
                'manifest_payload' => $manifestPayload,
                'manifest_hash' => $manifestHash,
                'status' => ActiveOnlineQuizSession::STATUS_ACTIVE,
                'started_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            // 8. Update guest active session pointer
            $lockedGuest->update(['active_quiz_session_id' => $sessionId]);

            // 9. Return client-safe questions (stripping answer keys, explanations, and internal weights)
            return [
                'session_id' => $sessionId,
                'quiz_mode' => $quizMode,
                'difficulty' => $difficulty,
                'category' => $category,
                'started_at' => $now->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
                'duration_seconds' => self::DEFAULT_SESSION_DURATION_MINUTES * 60,
                'total_questions' => $manifest->count(),
                'questions' => $manifest->toClientSafeArray(),
            ];
        });
    }

    /**
     * Authoritatively completes an active online quiz session, scores submitted answers,
     * updates lifetime and competitive XP ledger atomically, and transitions the session.
     *
     * @param GuestIdentity $guest Authenticated guest principal
     * @param string $sessionId Active online quiz session ID
     * @param array<int, array{question_id: string, selected_option_id: string, time_taken_ms?: int}> $submittedAnswers
     * @param string|null $eventId Optional idempotency event ID
     * @return array Authoritative score breakdown and progression state
     * @throws OnlineQuizException
     */
    public function completeSession(
        GuestIdentity $guest,
        string $sessionId,
        array $submittedAnswers,
        ?string $eventId = null
    ): array {
        $eventId = $eventId ?: (string) Str::uuid7();

        $submissionPayloadHash = hash('sha256', json_encode([
            'session_id' => $sessionId,
            'answers' => $submittedAnswers,
        ]));

        // Pre-transaction check: ownership and expired state persistence
        $preSession = ActiveOnlineQuizSession::where('id', $sessionId)->first();
        if ($preSession !== null) {
            if ((int) $preSession->guest_identity_id !== (int) $guest->id) {
                throw new OnlineQuizException(
                    "Session does not belong to authenticated guest.",
                    OnlineQuizException::CODE_SESSION_FORBIDDEN,
                    403
                );
            }

            if ($preSession->status === ActiveOnlineQuizSession::STATUS_ABANDONED) {
                throw new OnlineQuizException(
                    "Session has been abandoned and cannot be submitted.",
                    OnlineQuizException::CODE_SESSION_ABANDONED,
                    409
                );
            }

            if ($preSession->status === ActiveOnlineQuizSession::STATUS_EXPIRED || now()->greaterThan($preSession->expires_at)) {
                if ($preSession->status !== ActiveOnlineQuizSession::STATUS_EXPIRED) {
                    $preSession->update([
                        'status' => ActiveOnlineQuizSession::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);
                    GuestIdentity::where('id', $guest->id)
                        ->where('active_quiz_session_id', $sessionId)
                        ->update(['active_quiz_session_id' => null]);
                }

                throw new OnlineQuizException(
                    "Session has expired and cannot be submitted.",
                    OnlineQuizException::CODE_SESSION_EXPIRED,
                    422
                );
            }
        }

        return DB::transaction(function () use (
            $guest,
            $sessionId,
            $submittedAnswers,
            $eventId,
            $submissionPayloadHash
        ) {
            // 1. Lock guest identity row (first in lock hierarchy)
            /** @var GuestIdentity $lockedGuest */
            $lockedGuest = GuestIdentity::where('id', $guest->id)->lockForUpdate()->firstOrFail();

            if (!$lockedGuest->hasContact()) {
                throw new OnlineQuizException(
                    "Contact details are required to complete a quiz.",
                    OnlineQuizException::CODE_CONTACT_REQUIRED,
                    403
                );
            }

            if ($lockedGuest->isClaimed()) {
                throw new OnlineQuizException(
                    "Guest identity has been claimed.",
                    OnlineQuizException::CODE_SESSION_FORBIDDEN,
                    403
                );
            }

            // 2. Lock active session row (second in lock hierarchy)
            /** @var ActiveOnlineQuizSession|null $session */
            $session = ActiveOnlineQuizSession::where('id', $sessionId)->lockForUpdate()->first();

            if ($session === null) {
                throw new OnlineQuizException(
                    "Quiz session not found.",
                    OnlineQuizException::CODE_SESSION_NOT_FOUND,
                    404
                );
            }

            if ((int) $session->guest_identity_id !== (int) $lockedGuest->id) {
                throw new OnlineQuizException(
                    "Session does not belong to authenticated guest.",
                    OnlineQuizException::CODE_SESSION_FORBIDDEN,
                    403
                );
            }

            // 3. Handle terminal states and idempotency
            if ($session->status === ActiveOnlineQuizSession::STATUS_COMPLETED) {
                return $this->handleCompletedSessionReplay(
                    $session,
                    $eventId,
                    $submissionPayloadHash
                );
            }

            if ($session->status === ActiveOnlineQuizSession::STATUS_ABANDONED) {
                throw new OnlineQuizException(
                    "Session has been abandoned and cannot be submitted.",
                    OnlineQuizException::CODE_SESSION_ABANDONED,
                    409
                );
            }

            if ($session->status === ActiveOnlineQuizSession::STATUS_EXPIRED || now()->greaterThan($session->expires_at)) {
                if ($session->status !== ActiveOnlineQuizSession::STATUS_EXPIRED) {
                    $session->update([
                        'status' => ActiveOnlineQuizSession::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);
                    if ($lockedGuest->active_quiz_session_id === $sessionId) {
                        $lockedGuest->update(['active_quiz_session_id' => null]);
                    }
                }

                throw new OnlineQuizException(
                    "Session has expired and cannot be submitted.",
                    OnlineQuizException::CODE_SESSION_EXPIRED,
                    422
                );
            }

            // 4. Reconstruct server-authorized manifest from immutable revisions
            $manifest = $session->toAuthorizedManifest();

            // 5. Authoritatively score submitted answers
            try {
                $scoringResult = $this->scoringService->scoreAnswers($manifest, $submittedAnswers);
            } catch (ScoringValidationException $e) {
                throw new OnlineQuizException(
                    $e->getMessage(),
                    $e->errorCode,
                    422,
                    $e->context
                );
            }

            $now = now();
            $durationSeconds = max(1, $now->diffInSeconds($session->started_at));

            // 6. Award competitive XP via existing accounting service
            // (Third in lock hierarchy: daily counters locked inside service)
            $accountingResult = $this->accountingService->awardCompetitiveXp(
                userId: null,
                guestIdentityId: $lockedGuest->id,
                requestedXp: $scoringResult->grossXp,
                rewardSource: 'quiz_completion',
                sourceId: $session->id,
                eventId: $eventId,
                receivedAt: $now
            );

            $competitiveAwarded = (int) ($accountingResult['competitive_xp_awarded'] ?? 0);

            // 7. Persist historical completed session record into quiz_sessions
            DB::table('quiz_sessions')->insert([
                'id' => $session->id,
                'user_id' => null,
                'guest_identity_id' => $lockedGuest->id,
                'church_id' => null,
                'device_id' => $session->device_id,
                'quiz_mode' => $session->quiz_mode,
                'category' => $session->category,
                'difficulty' => $session->difficulty,
                'total_questions' => $scoringResult->totalQuestions,
                'correct_answers' => $scoringResult->correctAnswers,
                'claimed_score' => $scoringResult->grossXp,
                'verified_score' => $scoringResult->grossXp,
                'xp_earned' => $scoringResult->grossXp,
                'competitive_xp_awarded' => $competitiveAwarded,
                'duration_seconds' => $durationSeconds,
                'completed_at' => $now,
                'synced_at' => $now,
                'verification_status' => 'verified',
            ]);

            // 8. Persist item breakdown into quiz_session_items
            foreach ($scoringResult->itemResults as $item) {
                DB::table('quiz_session_items')->insert([
                    'session_id' => $session->id,
                    'question_id' => $item->questionId,
                    'question_version' => $item->version,
                    'selected_option_id' => $item->selectedOptionId,
                    'is_correct' => $item->isCorrect ? 1 : 0,
                    'time_taken_ms' => $item->timeTakenMs,
                ]);
            }

            // 9. Credit full validated gross XP to guest lifetime progression
            $newCompletedCount = $lockedGuest->completed_quiz_count + 1;
            $newGrossLifetimeXp = $lockedGuest->gross_lifetime_xp + $scoringResult->grossXp;
            $trialLimitReached = ($newCompletedCount >= self::MAX_TRIAL_QUIZZES || $newGrossLifetimeXp >= self::MAX_TRIAL_GROSS_XP);
            $trialLimitReachedAt = $lockedGuest->trial_limit_reached_at;
            if ($trialLimitReached && $trialLimitReachedAt === null) {
                $trialLimitReachedAt = $now;
            }

            $lockedGuest->update([
                'completed_quiz_count' => $newCompletedCount,
                'gross_lifetime_xp' => $newGrossLifetimeXp,
                'active_quiz_session_id' => null,
                'trial_limit_reached_at' => $trialLimitReachedAt,
            ]);

            // 10. Transition active session to completed (releases unique active_presence lock)
            $session->update([
                'status' => ActiveOnlineQuizSession::STATUS_COMPLETED,
                'completed_at' => $now,
            ]);

            // 11. Format authoritative response payload
            $responseData = [
                'session_id' => $session->id,
                'total_questions' => $scoringResult->totalQuestions,
                'correct_answers' => $scoringResult->correctAnswers,
                'gross_xp_earned' => $scoringResult->grossXp,
                'competitive_xp_awarded' => $competitiveAwarded,
                'guest_lifetime_xp' => $newGrossLifetimeXp,
                'completed_quiz_count' => $newCompletedCount,
                'trial_limit_reached' => $trialLimitReached,
                'trial_limit_reached_at' => $trialLimitReachedAt ? $trialLimitReachedAt->toISOString() : null,
                'is_duplicate' => false,
                'item_results' => array_map(fn($item) => $item->toArray(), $scoringResult->itemResults),
            ];

            // 12. Record in processed_events for durable idempotency
            DB::table('processed_events')->updateOrInsert(
                ['event_id' => $eventId],
                [
                    'session_id' => $session->id,
                    'device_id' => $session->device_id,
                    'guest_identity_id' => $lockedGuest->id,
                    'event_type' => 'quiz_completion',
                    'payload_hash' => $submissionPayloadHash,
                    'status' => 'accepted',
                    'http_status_code' => 200,
                    'response_payload' => json_encode($responseData),
                    'processed_at' => $now,
                ]
            );

            return $responseData;
        });
    }

    /**
     * Safely abandon an in-progress online quiz session.
     * Transitions session to abandoned and releases the guest active-session reservation.
     *
     * @throws OnlineQuizException
     */
    public function abandonSession(GuestIdentity $guest, string $sessionId): array
    {
        return DB::transaction(function () use ($guest, $sessionId) {
            /** @var GuestIdentity $lockedGuest */
            $lockedGuest = GuestIdentity::where('id', $guest->id)->lockForUpdate()->firstOrFail();

            /** @var ActiveOnlineQuizSession|null $session */
            $session = ActiveOnlineQuizSession::where('id', $sessionId)->lockForUpdate()->first();

            if ($session === null) {
                throw new OnlineQuizException(
                    "Quiz session not found.",
                    OnlineQuizException::CODE_SESSION_NOT_FOUND,
                    404
                );
            }

            if ((int) $session->guest_identity_id !== (int) $lockedGuest->id) {
                throw new OnlineQuizException(
                    "Session does not belong to authenticated guest.",
                    OnlineQuizException::CODE_SESSION_FORBIDDEN,
                    403
                );
            }

            if ($session->status === ActiveOnlineQuizSession::STATUS_ABANDONED) {
                return [
                    'session_id' => $sessionId,
                    'status' => ActiveOnlineQuizSession::STATUS_ABANDONED,
                    'abandoned_at' => $session->abandoned_at?->toISOString() ?? now()->toISOString(),
                    'is_duplicate' => true,
                ];
            }

            if ($session->status === ActiveOnlineQuizSession::STATUS_COMPLETED) {
                throw new OnlineQuizException(
                    "Cannot abandon an already completed quiz session.",
                    OnlineQuizException::CODE_SESSION_ALREADY_COMPLETED,
                    409
                );
            }

            $now = now();
            if ($session->status === ActiveOnlineQuizSession::STATUS_EXPIRED || $now->greaterThan($session->expires_at)) {
                if ($session->status !== ActiveOnlineQuizSession::STATUS_EXPIRED) {
                    $session->update([
                        'status' => ActiveOnlineQuizSession::STATUS_EXPIRED,
                        'expired_at' => $now,
                    ]);
                    if ($lockedGuest->active_quiz_session_id === $sessionId) {
                        $lockedGuest->update(['active_quiz_session_id' => null]);
                    }
                }

                throw new OnlineQuizException(
                    "Session has expired.",
                    OnlineQuizException::CODE_SESSION_EXPIRED,
                    422
                );
            }

            // Transition to abandoned
            $session->update([
                'status' => ActiveOnlineQuizSession::STATUS_ABANDONED,
                'abandoned_at' => $now,
            ]);

            if ($lockedGuest->active_quiz_session_id === $sessionId) {
                $lockedGuest->update(['active_quiz_session_id' => null]);
            }

            return [
                'session_id' => $sessionId,
                'status' => ActiveOnlineQuizSession::STATUS_ABANDONED,
                'abandoned_at' => $now->toISOString(),
                'is_duplicate' => false,
            ];
        });
    }

    /**
     * Retrieve authoritative guest trial capacity, accounting for active reservations and offline authorizations.
     */
    public function getTrialStatus(GuestIdentity $guest): array
    {
        return DB::transaction(function () use ($guest) {
            /** @var GuestIdentity $lockedGuest */
            $lockedGuest = GuestIdentity::where('id', $guest->id)->lockForUpdate()->firstOrFail();

            // Sweep stale active session if expired
            $this->sweepStaleActiveSessionLocked($lockedGuest);

            $activeSession = ActiveOnlineQuizSession::where('guest_identity_id', $lockedGuest->id)
                ->where('status', ActiveOnlineQuizSession::STATUS_ACTIVE)
                ->first();

            $unexpiredOffline = DB::table('offline_quiz_authorizations')
                ->where('guest_identity_id', $lockedGuest->id)
                ->where('status', 'issued')
                ->where('expires_at', '>', now())
                ->count();

            $activeOnlineCount = $activeSession !== null ? 1 : 0;
            $totalOpportunitiesConsumed = $lockedGuest->completed_quiz_count + $activeOnlineCount + $unexpiredOffline;
            $remainingQuizOpportunities = max(0, self::MAX_TRIAL_QUIZZES - $totalOpportunitiesConsumed);
            $remainingXpCapacity = max(0, self::MAX_TRIAL_GROSS_XP - $lockedGuest->gross_lifetime_xp);

            $trialLimitReached = (
                $lockedGuest->completed_quiz_count >= self::MAX_TRIAL_QUIZZES ||
                $lockedGuest->gross_lifetime_xp >= self::MAX_TRIAL_GROSS_XP ||
                $lockedGuest->trial_limit_reached_at !== null
            );

            return [
                'guest_uuid' => $lockedGuest->guest_uuid,
                'completed_quiz_count' => (int) $lockedGuest->completed_quiz_count,
                'max_quiz_count' => self::MAX_TRIAL_QUIZZES,
                'active_online_reservations' => $activeOnlineCount,
                'unexpired_offline_reservations' => $unexpiredOffline,
                'remaining_quiz_opportunities' => $remainingQuizOpportunities,
                'gross_lifetime_xp' => (int) $lockedGuest->gross_lifetime_xp,
                'max_lifetime_xp' => self::MAX_TRIAL_GROSS_XP,
                'remaining_xp_capacity' => $remainingXpCapacity,
                'has_contact' => $lockedGuest->hasContact(),
                'is_claimed' => $lockedGuest->isClaimed(),
                'trial_limit_reached' => $trialLimitReached,
                'trial_limit_reached_at' => $lockedGuest->trial_limit_reached_at?->toISOString(),
                'active_session' => $activeSession ? [
                    'session_id' => $activeSession->id,
                    'quiz_mode' => $activeSession->quiz_mode,
                    'difficulty' => $activeSession->difficulty,
                    'category' => $activeSession->category,
                    'started_at' => $activeSession->started_at->toISOString(),
                    'expires_at' => $activeSession->expires_at->toISOString(),
                ] : null,
            ];
        });
    }

    /**
     * Helper to sweep and persist expired transitions for active sessions whose timeout has elapsed.
     */
    protected function sweepStaleActiveSessionLocked(GuestIdentity $lockedGuest): void
    {
        $staleSession = ActiveOnlineQuizSession::where('guest_identity_id', $lockedGuest->id)
            ->where('status', ActiveOnlineQuizSession::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->lockForUpdate()
            ->first();

        if ($staleSession !== null) {
            $staleSession->update([
                'status' => ActiveOnlineQuizSession::STATUS_EXPIRED,
                'expired_at' => now(),
            ]);

            if ($lockedGuest->active_quiz_session_id === $staleSession->id) {
                $lockedGuest->update(['active_quiz_session_id' => null]);
            }
        }
    }

    /**
     * Handle idempotent replays and conflicting submissions for already completed sessions.
     *
     * @throws OnlineQuizException
     */
    protected function handleCompletedSessionReplay(
        ActiveOnlineQuizSession $session,
        string $eventId,
        string $incomingPayloadHash
    ): array {
        // 1. Check processed_events for this session
        $existingEvent = DB::table('processed_events')
            ->where('session_id', $session->id)
            ->first();

        if ($existingEvent !== null) {
            // Conflicting submission check
            if ($existingEvent->payload_hash !== $incomingPayloadHash) {
                throw new OnlineQuizException(
                    "Session has already been completed with different answers.",
                    OnlineQuizException::CODE_SESSION_ALREADY_COMPLETED,
                    409
                );
            }

            // Identical replay: return original stored payload
            $payload = json_decode($existingEvent->response_payload, true);
            if (is_array($payload)) {
                $payload['is_duplicate'] = true;
                return $payload;
            }
        }

        // 2. Reconstruct from quiz_sessions historical record if processed_events is missing
        $historical = DB::table('quiz_sessions')->where('id', $session->id)->first();
        if ($historical !== null) {
            return [
                'session_id' => $session->id,
                'total_questions' => (int) $historical->total_questions,
                'correct_answers' => (int) $historical->correct_answers,
                'gross_xp_earned' => (int) $historical->xp_earned,
                'competitive_xp_awarded' => (int) $historical->competitive_xp_awarded,
                'guest_lifetime_xp' => (int) $session->guestIdentity->gross_lifetime_xp,
                'completed_quiz_count' => (int) $session->guestIdentity->completed_quiz_count,
                'trial_limit_reached' => $session->guestIdentity->completed_quiz_count >= self::MAX_TRIAL_QUIZZES || $session->guestIdentity->gross_lifetime_xp >= self::MAX_TRIAL_GROSS_XP,
                'trial_limit_reached_at' => $session->guestIdentity->trial_limit_reached_at?->toISOString(),
                'is_duplicate' => true,
                'item_results' => [],
            ];
        }

        throw new OnlineQuizException(
            "Session has already been completed.",
            OnlineQuizException::CODE_SESSION_ALREADY_COMPLETED,
            409
        );
    }
}
