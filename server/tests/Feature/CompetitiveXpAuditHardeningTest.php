<?php

namespace Tests\Feature;

use App\Services\CompetitiveXpAccountingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class CompetitiveXpAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected CompetitiveXpAccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
        $this->accountingService = new CompetitiveXpAccountingService();
    }

    /**
     * Helper to create a test user and profile.
     */
    protected function createTestUser(): int
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Pilgrim ' . Str::random(5),
            'email' => 'pilgrim_' . Str::random(8) . '@example.com',
            'password' => bcrypt('secret123'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'pilgrim_name' => 'Pilgrim ' . $userId,
            'avatar_seed' => 'seed_' . $userId,
            'total_xp' => 0,
            'current_streak' => 1,
            'longest_streak' => 1,
            'is_leaderboard_public' => true,
            'show_church_affiliation' => false,
            'updated_at' => now(),
        ]);

        return $userId;
    }

    /**
     * Helper to create a test guest identity.
     */
    protected function createTestGuest(): int
    {
        return DB::table('guest_identities')->insertGetId([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('guest_secret_test_123', PASSWORD_BCRYPT),
            'created_at' => now(),
        ]);
    }

    /**
     * Test 1: User-owned counter satisfies the XOR ownership constraint.
     */
    public function test_user_owned_counter_satisfies_xor_constraint(): void
    {
        $userId = $this->createTestUser();

        DB::table('user_daily_competitive_counters')->insert([
            'principal_key' => 'U:' . $userId,
            'user_id' => $userId,
            'guest_identity_id' => null,
            'award_date' => '2026-10-17',
            'competitive_xp_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->first();

        $this->assertNotNull($counter);
        $this->assertEquals($userId, $counter->user_id);
        $this->assertNull($counter->guest_identity_id);
    }

    /**
     * Test 2: Guest-owned counter satisfies the XOR ownership constraint.
     */
    public function test_guest_owned_counter_satisfies_xor_constraint(): void
    {
        $guestId = $this->createTestGuest();

        DB::table('user_daily_competitive_counters')->insert([
            'principal_key' => 'G:' . $guestId,
            'user_id' => null,
            'guest_identity_id' => $guestId,
            'award_date' => '2026-10-17',
            'competitive_xp_total' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'G:' . $guestId)
            ->first();

        $this->assertNotNull($counter);
        $this->assertNull($counter->user_id);
        $this->assertEquals($guestId, $counter->guest_identity_id);
    }

    /**
     * Test 3: Both-owner counter (both user_id and guest_identity_id populated) is strictly rejected by XOR.
     */
    public function test_both_owner_counter_is_rejected_by_xor(): void
    {
        $userId = $this->createTestUser();
        $guestId = $this->createTestGuest();

        // 1. Service layer check: buildPrincipalKey throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: $guestId,
            requestedXp: 50,
            rewardSource: 'quiz_completion',
            sourceId: 'bad_both_owner',
            eventId: (string) Str::uuid7()
        );
    }

    /**
     * Test 4: Direct SQL insert of both-owner counter is rejected by MySQL CHECK constraint.
     */
    public function test_database_check_constraint_rejects_both_owner_counter(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints verified natively on MySQL.');
        }

        $userId = $this->createTestUser();
        $guestId = $this->createTestGuest();

        $this->expectException(QueryException::class);
        DB::statement(
            "INSERT INTO user_daily_competitive_counters
                (principal_key, user_id, guest_identity_id, award_date, competitive_xp_total, created_at, updated_at)
             VALUES (?, ?, ?, '2026-10-17', 50, NOW(), NOW())",
            ['INVALID:BOTH', $userId, $guestId]
        );
    }

    /**
     * Test 5: Ownerless counter (both user_id and guest_identity_id null) is strictly rejected by XOR.
     */
    public function test_ownerless_counter_is_rejected_by_xor(): void
    {
        // 1. Service layer check
        $this->expectException(InvalidArgumentException::class);
        $this->accountingService->awardCompetitiveXp(
            userId: null,
            guestIdentityId: null,
            requestedXp: 50,
            rewardSource: 'quiz_completion',
            sourceId: 'bad_ownerless',
            eventId: (string) Str::uuid7()
        );
    }

    /**
     * Test 6: Direct SQL insert of ownerless counter is rejected by MySQL CHECK constraint.
     */
    public function test_database_check_constraint_rejects_ownerless_counter(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints verified natively on MySQL.');
        }

        $this->expectException(QueryException::class);
        DB::statement(
            "INSERT INTO user_daily_competitive_counters
                (principal_key, user_id, guest_identity_id, award_date, competitive_xp_total, created_at, updated_at)
             VALUES (?, NULL, NULL, '2026-10-17', 50, NOW(), NOW())",
            ['INVALID:NONE']
        );
    }

    /**
     * Test 7: Valid competitive awards create typed audit records in competitive_xp_events.
     */
    public function test_valid_competitive_awards_create_typed_audit_events(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-17 14:00:00', 'UTC');

        $result = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 150,
            rewardSource: 'quiz_completion',
            sourceId: 'quiz_session_001',
            eventId: $eventId,
            receivedAt: $time
        );

        $this->assertEquals(150, $result['competitive_xp_awarded']);
        $this->assertEquals(150, $result['daily_total_after_award']);

        // Assert audit record in competitive_xp_events
        $this->assertDatabaseHas('competitive_xp_events', [
            'principal_key' => 'U:' . $userId,
            'award_date' => '2026-10-17',
            'event_type' => 'quiz_completion',
            'xp_delta' => 150,
            'counter_before' => 0,
            'counter_after' => 150,
            'reference_id' => 'quiz_session_001',
            'event_id' => $eventId,
        ]);

        $auditEvents = $this->accountingService->getAuditEvents('U:' . $userId, '2026-10-17');
        $this->assertCount(1, $auditEvents);
        $this->assertEquals(150, $auditEvents->first()->xp_delta);
        $this->assertEquals(0, $auditEvents->first()->counter_before);
        $this->assertEquals(150, $auditEvents->first()->counter_after);
    }

    /**
     * Test 8: Sequential awards accurately record counter_before, counter_after, and xp_delta.
     */
    public function test_counter_before_and_counter_after_values_accurately_reflect_sequential_changes(): void
    {
        $userId = $this->createTestUser();
        $time = Carbon::parse('2026-10-17 10:00:00', 'UTC');

        // Event 1: 150 XP award
        $eventId1 = (string) Str::uuid7();
        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 150,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_1',
            eventId: $eventId1,
            receivedAt: $time
        );

        // Event 2: 200 XP award
        $eventId2 = (string) Str::uuid7();
        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 200,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_2',
            eventId: $eventId2,
            receivedAt: $time
        );

        // Event 3: 100 XP award (capped to 50 because headroom is 400 - 350 = 50)
        $eventId3 = (string) Str::uuid7();
        $res3 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_3',
            eventId: $eventId3,
            receivedAt: $time
        );

        $this->assertEquals(50, $res3['competitive_xp_awarded']);
        $this->assertEquals(400, $res3['daily_total_after_award']);
        $this->assertTrue($res3['is_capped']);

        $events = $this->accountingService->getAuditEvents('U:' . $userId, '2026-10-17');
        $this->assertCount(3, $events);

        // Verify Event 1
        $this->assertEquals(0, $events[0]->counter_before);
        $this->assertEquals(150, $events[0]->counter_after);
        $this->assertEquals(150, $events[0]->xp_delta);
        $this->assertEquals($eventId1, $events[0]->event_id);

        // Verify Event 2
        $this->assertEquals(150, $events[1]->counter_before);
        $this->assertEquals(350, $events[1]->counter_after);
        $this->assertEquals(200, $events[1]->xp_delta);
        $this->assertEquals($eventId2, $events[1]->event_id);

        // Verify Event 3 (capped delta)
        $this->assertEquals(350, $events[2]->counter_before);
        $this->assertEquals(400, $events[2]->counter_after);
        $this->assertEquals(50, $events[2]->xp_delta);
        $this->assertEquals($eventId3, $events[2]->event_id);

        // Mathematical invariant: sum of xp_delta equals final counter total
        $sumDelta = $events->sum('xp_delta');
        $this->assertEquals(400, $sumDelta);
    }

    /**
     * Test 9: Duplicate event retries return duplicate status and do NOT create duplicate audit records.
     */
    public function test_duplicate_event_retries_do_not_create_duplicate_audit_records(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-17 11:00:00', 'UTC');

        // First attempt
        $res1 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_retry_test',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertFalse($res1['is_duplicate']);
        $this->assertEquals(100, $res1['competitive_xp_awarded']);

        // Assert exactly 1 audit record exists
        $this->assertEquals(1, DB::table('competitive_xp_events')->where('principal_key', 'U:' . $userId)->count());

        // Duplicate replay with identical event_id and payload
        $res2 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_retry_test',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertTrue($res2['is_duplicate']);
        $this->assertEquals(0, $res2['competitive_xp_awarded']);

        // Assert audit record count remains strictly 1 (no duplicate row created)
        $this->assertEquals(1, DB::table('competitive_xp_events')->where('principal_key', 'U:' . $userId)->count());
    }

    /**
     * Test 10: Event ID uniqueness constraint in competitive_xp_events rejects duplicate inserts.
     */
    public function test_audit_event_table_enforces_event_id_uniqueness(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();

        DB::table('competitive_xp_events')->insert([
            'principal_key' => 'U:' . $userId,
            'award_date' => '2026-10-17',
            'event_type' => 'quiz_completion',
            'xp_delta' => 100,
            'counter_before' => 0,
            'counter_after' => 100,
            'reference_id' => 'ref_1',
            'event_id' => $eventId,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        // Direct attempt to insert a duplicate event_id must fail unique key constraint
        DB::table('competitive_xp_events')->insert([
            'principal_key' => 'U:' . $userId,
            'award_date' => '2026-10-17',
            'event_type' => 'quiz_completion',
            'xp_delta' => 100,
            'counter_before' => 100,
            'counter_after' => 200,
            'reference_id' => 'ref_2',
            'event_id' => $eventId,
            'created_at' => now(),
        ]);
    }

    /**
     * Test 11: Transaction rollback removes both counter update and audit event mutations.
     */
    public function test_transaction_rollback_removes_both_award_and_audit_mutations(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-17 12:00:00', 'UTC');

        try {
            DB::transaction(function () use ($userId, $eventId, $time) {
                $this->accountingService->awardCompetitiveXp(
                    userId: $userId,
                    guestIdentityId: null,
                    requestedXp: 120,
                    rewardSource: 'quiz_completion',
                    sourceId: 'atomic_test_sess',
                    eventId: $eventId,
                    receivedAt: $time
                );

                throw new \RuntimeException("Simulated crash to test atomicity");
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Daily counter must not exist
        $this->assertNull(
            DB::table('user_daily_competitive_counters')->where('principal_key', 'U:' . $userId)->first()
        );

        // Audit events must be completely rolled back
        $this->assertEquals(0, DB::table('competitive_xp_events')->where('principal_key', 'U:' . $userId)->count());

        // Processed events must be completely rolled back
        $this->assertEquals(0, DB::table('processed_events')->where('user_id', $userId)->count());
    }

    /**
     * Test 12: Guest principal awards create guest audit records under G:<guestId>.
     */
    public function test_guest_principal_award_creates_guest_audit_event(): void
    {
        $guestId = $this->createTestGuest();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-17 15:30:00', 'UTC');

        $result = $this->accountingService->awardCompetitiveXp(
            userId: null,
            guestIdentityId: $guestId,
            requestedXp: 120,
            rewardSource: 'quiz_completion',
            sourceId: 'guest_sess_101',
            eventId: $eventId,
            receivedAt: $time
        );

        $this->assertEquals('G:' . $guestId, $result['principal_key']);
        $this->assertEquals(120, $result['competitive_xp_awarded']);

        $this->assertDatabaseHas('competitive_xp_events', [
            'principal_key' => 'G:' . $guestId,
            'award_date' => '2026-10-17',
            'event_type' => 'quiz_completion',
            'xp_delta' => 120,
            'counter_before' => 0,
            'counter_after' => 120,
            'reference_id' => 'guest_sess_101',
            'event_id' => $eventId,
        ]);
    }
}
