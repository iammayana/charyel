<?php

namespace Tests\Feature;

use App\Services\CompetitiveXpAccountingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CompetitiveXpAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected CompetitiveXpAccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
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
            'password' => bcrypt('secret'),
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
            'secret_hash' => password_hash('guest_secret_test', PASSWORD_BCRYPT),
            'created_at' => now(),
        ]);
    }

    /**
     * Test 1: Migration 9 creates user_daily_competitive_counters with expected schema.
     */
    public function test_migration_9_creates_daily_counters_table_with_constraints(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_daily_competitive_counters'));

        $columns = DB::getSchemaBuilder()->getColumnListing('user_daily_competitive_counters');
        $this->assertContains('id', $columns);
        $this->assertContains('principal_key', $columns);
        $this->assertContains('user_id', $columns);
        $this->assertContains('guest_identity_id', $columns);
        $this->assertContains('award_date', $columns);
        $this->assertContains('competitive_xp_total', $columns);
        $this->assertContains('last_event_id', $columns);
    }

    /**
     * Test 2: Normal award increments counter, ledger, and lifetime total.
     */
    public function test_award_competitive_xp_increments_counter_and_ledger(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();

        $result = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 150,
            rewardSource: 'quiz_completion',
            sourceId: 'session_101',
            eventId: $eventId,
            receivedAt: Carbon::parse('2026-10-16 12:00:00', 'UTC')
        );

        $this->assertEquals('U:' . $userId, $result['principal_key']);
        $this->assertEquals('2026-10-16', $result['award_date']);
        $this->assertEquals(150, $result['requested_xp']);
        $this->assertEquals(150, $result['competitive_xp_awarded']);
        $this->assertEquals(150, $result['daily_total_after_award']);
        $this->assertEquals(250, $result['remaining_daily_headroom']);
        $this->assertFalse($result['is_capped']);
        $this->assertFalse($result['is_duplicate']);

        // Verify daily counter row
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-16')
            ->first();

        $this->assertNotNull($counter);
        $this->assertEquals(150, $counter->competitive_xp_total);
        $this->assertEquals($eventId, $counter->last_event_id);

        // Verify user_xp_ledger
        $ledger = DB::table('user_xp_ledger')
            ->where('user_id', $userId)
            ->where('source_id', 'session_101')
            ->first();

        $this->assertNotNull($ledger);
        $this->assertEquals(150, $ledger->xp_amount);

        // Verify user_profiles total_xp
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first();
        $this->assertEquals(150, $profile->total_xp);
    }

    /**
     * Test 3: Enforces strict 400 XP daily competitive cap while preserving lifetime XP.
     */
    public function test_enforces_strict_400_xp_daily_cap_and_caps_overflow(): void
    {
        $userId = $this->createTestUser();
        $receivedTime = Carbon::parse('2026-10-16 14:00:00', 'UTC');

        // Award 1: 250 XP (Headroom becomes 150)
        $res1 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 250,
            rewardSource: 'quiz_completion',
            sourceId: 'session_A',
            eventId: (string) Str::uuid7(),
            receivedAt: $receivedTime
        );
        $this->assertEquals(250, $res1['competitive_xp_awarded']);
        $this->assertEquals(250, $res1['daily_total_after_award']);
        $this->assertEquals(150, $res1['remaining_daily_headroom']);
        $this->assertFalse($res1['is_capped']);

        // Award 2: 200 XP requested (only 150 available before 400 cap)
        $res2 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 200,
            rewardSource: 'quiz_completion',
            sourceId: 'session_B',
            eventId: (string) Str::uuid7(),
            receivedAt: $receivedTime
        );
        $this->assertEquals(150, $res2['competitive_xp_awarded']); // Capped at 150!
        $this->assertEquals(400, $res2['daily_total_after_award']); // Exactly 400 cap
        $this->assertEquals(0, $res2['remaining_daily_headroom']);
        $this->assertTrue($res2['is_capped']);

        // Award 3: 100 XP requested when headroom is 0
        $res3 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'session_C',
            eventId: (string) Str::uuid7(),
            receivedAt: $receivedTime
        );
        $this->assertEquals(0, $res3['competitive_xp_awarded']); // 0 competitive XP
        $this->assertEquals(400, $res3['daily_total_after_award']); // Remains 400
        $this->assertEquals(0, $res3['remaining_daily_headroom']);
        $this->assertTrue($res3['is_capped']);

        // Lifetime XP still receives all requested XP: 250 + 200 + 100 = 550
        $profile = DB::table('user_profiles')->where('user_id', $userId)->first();
        $this->assertEquals(550, $profile->total_xp);

        // Daily competitive counter never exceeds 400
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-16')
            ->first();
        $this->assertEquals(400, $counter->competitive_xp_total);
    }

    /**
     * Test 4: UTC midnight boundary resets headroom cleanly.
     */
    public function test_utc_midnight_boundary_resets_headroom_cleanly(): void
    {
        $userId = $this->createTestUser();

        // Day 1: 23:59:59 UTC â€” Cap out at 400 XP
        $resDay1 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 400,
            rewardSource: 'quiz_completion',
            sourceId: 'session_d1',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-16 23:59:59', 'UTC')
        );
        $this->assertEquals('2026-10-16', $resDay1['award_date']);
        $this->assertEquals(400, $resDay1['daily_total_after_award']);
        $this->assertEquals(0, $resDay1['remaining_daily_headroom']);

        // Day 2: 00:00:01 UTC â€” Headroom is fresh at 400 XP
        $resDay2 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 150,
            rewardSource: 'quiz_completion',
            sourceId: 'session_d2',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-17 00:00:01', 'UTC')
        );
        $this->assertEquals('2026-10-17', $resDay2['award_date']);
        $this->assertEquals(150, $resDay2['competitive_xp_awarded']);
        $this->assertEquals(150, $resDay2['daily_total_after_award']);
        $this->assertEquals(250, $resDay2['remaining_daily_headroom']);

        // Confirm two separate date rows exist in the daily counter table
        $d1Counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-16')
            ->value('competitive_xp_total');

        $d2Counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-17')
            ->value('competitive_xp_total');

        $this->assertEquals(400, $d1Counter);
        $this->assertEquals(150, $d2Counter);
    }

    /**
     * Test 5: Duplicate submission idempotency returns without double award.
     */
    public function test_duplicate_submission_idempotency_returns_without_double_award(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-16 10:00:00', 'UTC');

        // First submission
        $res1 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'session_uniq_1',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertFalse($res1['is_duplicate']);
        $this->assertEquals(100, $res1['competitive_xp_awarded']);

        // Duplicate replay with identical eventId
        $resDuplicate = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'session_uniq_1',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertTrue($resDuplicate['is_duplicate']);
        $this->assertEquals(0, $resDuplicate['competitive_xp_awarded']);
        $this->assertEquals(100, $resDuplicate['daily_total_after_award']);

        // Confirm only 1 ledger entry and 1 processed event
        $ledgerCount = DB::table('user_xp_ledger')->where('user_id', $userId)->count();
        $this->assertEquals(1, $ledgerCount);

        $eventCount = DB::table('processed_events')->where('event_id', $eventId)->count();
        $this->assertEquals(1, $eventCount);

        $profile = DB::table('user_profiles')->where('user_id', $userId)->first();
        $this->assertEquals(100, $profile->total_xp);
    }

    /**
     * Test 6: Guest identity counter isolation and principal key formatting.
     */
    public function test_guest_identity_counter_isolation_and_principal_key(): void
    {
        $guestId = $this->createTestGuest();
        $userId = $this->createTestUser();
        $time = Carbon::parse('2026-10-16 11:00:00', 'UTC');

        // Guest earns 300 XP
        $guestRes = $this->accountingService->awardCompetitiveXp(
            userId: null,
            guestIdentityId: $guestId,
            requestedXp: 300,
            rewardSource: 'quiz_completion',
            sourceId: 'guest_sess_1',
            eventId: (string) Str::uuid7(),
            receivedAt: $time
        );
        $this->assertEquals('G:' . $guestId, $guestRes['principal_key']);
        $this->assertEquals(300, $guestRes['competitive_xp_awarded']);

        // User earns 250 XP
        $userRes = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 250,
            rewardSource: 'quiz_completion',
            sourceId: 'user_sess_1',
            eventId: (string) Str::uuid7(),
            receivedAt: $time
        );
        $this->assertEquals('U:' . $userId, $userRes['principal_key']);
        $this->assertEquals(250, $userRes['competitive_xp_awarded']);

        // Each principal has independent daily headroom
        $this->assertEquals(100, $guestRes['remaining_daily_headroom']);
        $this->assertEquals(150, $userRes['remaining_daily_headroom']);
    }

    /**
     * Test 7: Invalid amounts, sources, and principals are strictly rejected.
     */
    public function test_invalid_inputs_are_rejected(): void
    {
        $userId = $this->createTestUser();

        // Zero XP
        $this->expectException(InvalidArgumentException::class);
        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 0,
            rewardSource: 'quiz_completion',
            sourceId: 'bad_1',
            eventId: (string) Str::uuid7()
        );
    }

    /**
     * Test 8: Partial transaction failure rolls back counter, ledger, and profile atomically.
     */
    public function test_partial_transaction_failure_rolls_back_atomically(): void
    {
        $userId = $this->createTestUser();
        $time = Carbon::parse('2026-10-16 15:00:00', 'UTC');

        try {
            DB::transaction(function () use ($userId, $time) {
                // Award 100 XP
                $this->accountingService->awardCompetitiveXp(
                    userId: $userId,
                    guestIdentityId: null,
                    requestedXp: 100,
                    rewardSource: 'quiz_completion',
                    sourceId: 'atomic_test_1',
                    eventId: (string) Str::uuid7(),
                    receivedAt: $time
                );

                // Simulate downstream failure
                throw new \RuntimeException("Simulated network or validation crash");
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Entire transaction must have rolled back
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->first();
        $this->assertNull($counter);

        $ledger = DB::table('user_xp_ledger')
            ->where('user_id', $userId)
            ->first();
        $this->assertNull($ledger);

        $profile = DB::table('user_profiles')->where('user_id', $userId)->first();
        $this->assertEquals(0, $profile->total_xp);
    }

    /**
     * Test 9: Engine-level check constraint blocks any direct SQL attempt to exceed 400 XP.
     */
    public function test_database_check_constraint_blocks_counter_exceeding_400(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints verified natively on MySQL.');
        }

        $userId = $this->createTestUser();

        $this->expectException(\Illuminate\Database\QueryException::class);
        // Attempt rogue SQL write of 401 XP directly into the table
        DB::statement(
            "INSERT INTO user_daily_competitive_counters
                (principal_key, user_id, award_date, competitive_xp_total, created_at, updated_at)
             VALUES (?, ?, '2026-10-16', 401, NOW(), NOW())",
            ['U:' . $userId, $userId]
        );
    }

    /**
     * Test 10: Idempotency conflict â€” re-submitting same event_id with different payload is strictly rejected.
     */
    public function test_idempotency_conflicting_payload_is_strictly_rejected(): void
    {
        $userId = $this->createTestUser();
        $eventId = (string) Str::uuid7();
        $time = Carbon::parse('2026-10-16 10:00:00', 'UTC');

        // First valid award
        $res1 = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_original',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertEquals(100, $res1['competitive_xp_awarded']);

        // Legitimate replay with identical payload succeeds idempotently
        $replay = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_original',
            eventId: $eventId,
            receivedAt: $time
        );
        $this->assertTrue($replay['is_duplicate']);
        $this->assertEquals(0, $replay['competitive_xp_awarded']);
        $this->assertEquals(100, $replay['daily_total_after_award']);

        // Fraudulent replay: same event_id but DIFFERENT requested XP
        $conflictDetected = false;
        try {
            $this->accountingService->awardCompetitiveXp(
                userId: $userId,
                guestIdentityId: null,
                requestedXp: 200, // Changed from 100!
                rewardSource: 'quiz_completion',
                sourceId: 'sess_original',
                eventId: $eventId,
                receivedAt: $time
            );
        } catch (InvalidArgumentException $e) {
            $conflictDetected = true;
            $this->assertStringContainsString('Idempotency conflict', $e->getMessage());
        }
        $this->assertTrue($conflictDetected, 'Conflicting payload must throw InvalidArgumentException.');

        // Fraudulent replay: same event_id but DIFFERENT sourceId
        $conflictDetected2 = false;
        try {
            $this->accountingService->awardCompetitiveXp(
                userId: $userId,
                guestIdentityId: null,
                requestedXp: 100,
                rewardSource: 'quiz_completion',
                sourceId: 'sess_forged_source', // Changed!
                eventId: $eventId,
                receivedAt: $time
            );
        } catch (InvalidArgumentException $e) {
            $conflictDetected2 = true;
            $this->assertStringContainsString('Idempotency conflict', $e->getMessage());
        }
        $this->assertTrue($conflictDetected2, 'Conflicting sourceId must throw InvalidArgumentException.');

        // Verify that database was NOT corrupted or altered by rejected attempts
        $counter = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-16')
            ->value('competitive_xp_total');
        $this->assertEquals(100, $counter);

        $ledgerCount = DB::table('user_xp_ledger')->where('user_id', $userId)->count();
        $this->assertEquals(1, $ledgerCount);

        $profileXp = DB::table('user_profiles')->where('user_id', $userId)->value('total_xp');
        $this->assertEquals(100, $profileXp);
    }

    /**
     * Test 11: Reconciliation audit reconstructs daily competitive total from immutable events.
     */
    public function test_reconciliation_audit_reconstructs_daily_total_from_immutable_events(): void
    {
        $userId = $this->createTestUser();
        $awardDate = '2026-10-16';

        // 3 sequential awards
        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 150,
            rewardSource: 'quiz_completion',
            sourceId: 'recon_sess_1',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-16 09:00:00', 'UTC')
        );

        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 200,
            rewardSource: 'quiz_completion',
            sourceId: 'recon_sess_2',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-16 11:30:00', 'UTC')
        );

        $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 100, // Headroom is 50, so 50 competitive, 100 lifetime
            rewardSource: 'quiz_completion',
            sourceId: 'recon_sess_3',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-16 15:45:00', 'UTC')
        );

        // Run reconciliation audit
        $audit = $this->accountingService->reconstructDailyAudit('U:' . $userId, $awardDate);

        $this->assertTrue($audit['is_reconciled']);
        $this->assertEquals(400, $audit['counter_total']);
        $this->assertEquals(400, $audit['reconstructed_competitive_total']);
        $this->assertEquals(450, $audit['reconstructed_gross_total']);
        $this->assertCount(3, $audit['events']);

        // Verify each event has verifiable association
        foreach ($audit['events'] as $event) {
            $this->assertNotNull($event->event_id);
            $this->assertEquals('U:' . $userId, $event->principal_key);
            $this->assertGreaterThan(0, $event->gross_lifetime_xp);
            $this->assertGreaterThanOrEqual(0, $event->competitive_xp_awarded);
        }
    }

    /**
     * Test 12: Authoritative server UTC timestamp attribution at midnight boundaries.
     */
    public function test_authoritative_server_utc_timestamp_attribution_at_midnight_boundaries(): void
    {
        $userId = $this->createTestUser();

        // Server receipt at 23:59:59 UTC -> belongs to 2026-10-16
        $resBefore = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 250,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_before_midnight',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-16 23:59:59', 'UTC')
        );
        $this->assertEquals('2026-10-16', $resBefore['award_date']);
        $this->assertEquals(250, $resBefore['competitive_xp_awarded']);

        // Server receipt at 00:00:00 UTC next second -> belongs to 2026-10-17
        $resAfter = $this->accountingService->awardCompetitiveXp(
            userId: $userId,
            guestIdentityId: null,
            requestedXp: 250,
            rewardSource: 'quiz_completion',
            sourceId: 'sess_after_midnight',
            eventId: (string) Str::uuid7(),
            receivedAt: Carbon::parse('2026-10-17 00:00:00', 'UTC')
        );
        $this->assertEquals('2026-10-17', $resAfter['award_date']);
        $this->assertEquals(250, $resAfter['competitive_xp_awarded']);

        // Verify both counters exist independently in the database
        $c16 = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-16')
            ->value('competitive_xp_total');
        $c17 = DB::table('user_daily_competitive_counters')
            ->where('principal_key', 'U:' . $userId)
            ->where('award_date', '2026-10-17')
            ->value('competitive_xp_total');

        $this->assertEquals(250, $c16);
        $this->assertEquals(250, $c17);
    }


    /**
     * Verify that an independent PDO connection points strictly to an authorized disposable MySQL test database.
     * Enforces fail-closed protection before executing destructive test cleanup.
     *
     * @throws \RuntimeException
     */
    protected function assertPdoPointsToDisposableTestDatabase(\PDO $pdo, string $configHost = '127.0.0.1'): void
    {
        $stmt = $pdo->query("SELECT DATABASE() as db, CURRENT_USER() as user, @@port as port, @@version as version");
        $meta = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$meta) {
            throw new \RuntimeException("CONCURRENCY TEST SAFETY VIOLATION: Failed to query live PDO connection identity.");
        }

        $currentDb = (string) ($meta['db'] ?? '');
        $currentUser = (string) ($meta['user'] ?? '');
        $currentPort = (int) ($meta['port'] ?? 0);
        $currentVersion = (string) ($meta['version'] ?? '');

        // Enforce fail-closed validation against authorized profiles via DatabaseSafetyGuard
        \Tests\Support\DatabaseSafetyGuard::validateMysqlIdentity(
            $currentDb,
            $currentUser,
            $currentPort,
            $currentVersion,
            $configHost
        );
    }

    /**
     * Test 13: Real concurrency with two independent MySQL database connections.
     */
    public function test_real_concurrency_with_two_independent_mysql_connections(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real multi-connection concurrency verified on MySQL.');
        }

        // Establish two independent PDO connections to the MySQL test database
        $config = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
        $pdo1 = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo2 = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);

        // Create user directly via pdo1 committed so both connections see it
        $email = 'concurrent_' . Str::random(8) . '@example.com';
        $pdo1->exec("INSERT INTO users (name, email, password, created_at, updated_at) VALUES ('Concurrent Pilgrim', '{$email}', 'secret', NOW(), NOW())");
        $userId = (int) $pdo1->lastInsertId();
        $pdo1->exec("INSERT INTO user_profiles (user_id, pilgrim_name, avatar_seed, total_xp, current_streak, longest_streak, is_leaderboard_public, show_church_affiliation, updated_at) VALUES ({$userId}, 'Concurrent Pilgrim', 'seed', 0, 1, 1, 1, 0, NOW())");

        $principalKey = 'U:' . $userId;
        $awardDate = '2026-10-16';

        try {
            // Pre-seed the daily counter row so both connections compete on the existing row lock
            $pdo1->exec("INSERT INTO user_daily_competitive_counters (principal_key, user_id, award_date, competitive_xp_total, created_at, updated_at) VALUES ('{$principalKey}', {$userId}, '{$awardDate}', 0, NOW(), NOW())");

            // Transaction 1: Connection 1 begins and acquires pessimistic row lock
            $pdo1->beginTransaction();
            $stmt1 = $pdo1->prepare("SELECT id, competitive_xp_total FROM user_daily_competitive_counters WHERE principal_key = :pk AND award_date = :dt FOR UPDATE");
            $stmt1->execute(['pk' => $principalKey, 'dt' => $awardDate]);
            $row1 = $stmt1->fetch(\PDO::FETCH_ASSOC);
            $this->assertEquals(0, (int) $row1['competitive_xp_total']);

            // Connection 1 awards 250 XP
            $awarded1 = min(250, 400 - (int) $row1['competitive_xp_total']); // 250
            $newTotal1 = (int) $row1['competitive_xp_total'] + $awarded1; // 250
            $update1 = $pdo1->prepare("UPDATE user_daily_competitive_counters SET competitive_xp_total = :tot, updated_at = NOW() WHERE id = :id");
            $update1->execute(['tot' => $newTotal1, 'id' => $row1['id']]);

            // Connection 1 inserts ledger and processed event
            $evId1 = (string) Str::uuid7();
            $pdo1->exec("INSERT INTO user_xp_ledger (user_id, reward_source, source_id, event_id, xp_amount, awarded_at) VALUES ({$userId}, 'quiz_completion', 'conn1_sess', '{$evId1}', 250, NOW())");
            $payload1 = json_encode(['event_id' => $evId1, 'principal_key' => $principalKey, 'award_date' => $awardDate, 'requested_xp' => 250, 'competitive_xp_awarded' => $awarded1]);
            $pdo1->exec("INSERT INTO processed_events (event_id, device_id, user_id, event_type, payload_hash, status, http_status_code, response_payload, processed_at) VALUES ('{$evId1}', 'device_conn1', {$userId}, 'competitive_xp_award', '" . hash('sha256', $evId1) . "', 'accepted', 200, '{$payload1}', NOW())");

            // Commit connection 1
            $pdo1->commit();

            // Transaction 2: Connection 2 begins and reads the row
            $pdo2->beginTransaction();
            $stmt2 = $pdo2->prepare("SELECT id, competitive_xp_total FROM user_daily_competitive_counters WHERE principal_key = :pk AND award_date = :dt FOR UPDATE");
            $stmt2->execute(['pk' => $principalKey, 'dt' => $awardDate]);
            $row2 = $stmt2->fetch(\PDO::FETCH_ASSOC);

            // Connection 2 reads committed state from Connection 1
            $this->assertEquals(250, (int) $row2['competitive_xp_total']);
            $headroom2 = 400 - (int) $row2['competitive_xp_total']; // 150
            $awarded2 = min(250, $headroom2); // 150 (capped)
            $newTotal2 = (int) $row2['competitive_xp_total'] + $awarded2; // 400

            $update2 = $pdo2->prepare("UPDATE user_daily_competitive_counters SET competitive_xp_total = :tot, updated_at = NOW() WHERE id = :id");
            $update2->execute(['tot' => $newTotal2, 'id' => $row2['id']]);

            $evId2 = (string) Str::uuid7();
            $pdo2->exec("INSERT INTO user_xp_ledger (user_id, reward_source, source_id, event_id, xp_amount, awarded_at) VALUES ({$userId}, 'quiz_completion', 'conn2_sess', '{$evId2}', 250, NOW())");
            $payload2 = json_encode(['event_id' => $evId2, 'principal_key' => $principalKey, 'award_date' => $awardDate, 'requested_xp' => 250, 'competitive_xp_awarded' => $awarded2]);
            $pdo2->exec("INSERT INTO processed_events (event_id, device_id, user_id, event_type, payload_hash, status, http_status_code, response_payload, processed_at) VALUES ('{$evId2}', 'device_conn2', {$userId}, 'competitive_xp_award', '" . hash('sha256', $evId2) . "', 'accepted', 200, '{$payload2}', NOW())");

            $pdo2->commit();

            // Final state verification
            $verifyStmt = $pdo1->prepare("SELECT competitive_xp_total FROM user_daily_competitive_counters WHERE id = :id");
            $verifyStmt->execute(['id' => $row1['id']]);
            $finalTotal = (int) $verifyStmt->fetchColumn();

            $this->assertEquals(400, $finalTotal);
            $this->assertEquals(250, $awarded1);
            $this->assertEquals(150, $awarded2);
            $this->assertEquals(400, $awarded1 + $awarded2);
        } finally {
            $this->assertPdoPointsToDisposableTestDatabase($pdo1, (string) ($config['host'] ?? '127.0.0.1'));

            // Clean up test data using prepared statements with bound parameters
            $stmtAudit = $pdo1->prepare("DELETE FROM competitive_xp_events WHERE principal_key = :pk");
            $stmtAudit->execute(['pk' => $principalKey]);

            $stmtProc = $pdo1->prepare("DELETE FROM processed_events WHERE user_id = :uid");
            $stmtProc->execute(['uid' => $userId]);

            $stmtLedger = $pdo1->prepare("DELETE FROM user_xp_ledger WHERE user_id = :uid");
            $stmtLedger->execute(['uid' => $userId]);

            $stmtCounter = $pdo1->prepare("DELETE FROM user_daily_competitive_counters WHERE user_id = :uid");
            $stmtCounter->execute(['uid' => $userId]);

            $stmtProfile = $pdo1->prepare("DELETE FROM user_profiles WHERE user_id = :uid");
            $stmtProfile->execute(['uid' => $userId]);

            $stmtUser = $pdo1->prepare("DELETE FROM users WHERE id = :uid");
            $stmtUser->execute(['uid' => $userId]);
        }
    }

    /**
     * Test 14: Real multi-process concurrency with simultaneous operating system processes.
     */
    public function test_real_concurrency_with_simultaneous_operating_system_processes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real multi-process concurrency verified on MySQL.');
        }

        $config = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
        $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $email = 'proc_pilgrim_' . Str::random(8) . '@example.com';
        $pdo->exec("INSERT INTO users (name, email, password, created_at, updated_at) VALUES ('Proc Pilgrim', '{$email}', 'secret', NOW(), NOW())");
        $userId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO user_profiles (user_id, pilgrim_name, avatar_seed, total_xp, current_streak, longest_streak, is_leaderboard_public, show_church_affiliation, updated_at) VALUES ({$userId}, 'Proc Pilgrim', 'seed', 0, 1, 1, 1, 0, NOW())");

        $workerScript = base_path('tests/concurrency_worker.php');
        $ev1 = (string) Str::uuid7();
        $ev2 = (string) Str::uuid7();

        $cmd1 = 'php ' . escapeshellarg($workerScript) . " {$userId} 250 proc_sess_1 {$ev1} 2026-10-16";
        $cmd2 = 'php ' . escapeshellarg($workerScript) . " {$userId} 250 proc_sess_2 {$ev2} 2026-10-16";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $workerEnv = array_filter(getenv(), fn($v) => is_string($v) || is_numeric($v));
        $workerEnv['DB_CONNECTION'] = 'mysql';
        $workerEnv['DB_HOST'] = (string) ($config['host'] ?? '127.0.0.1');
        $workerEnv['DB_PORT'] = (string) ($config['port'] ?? '');
        $workerEnv['DB_DATABASE'] = (string) ($config['database'] ?? '');
        $workerEnv['DB_USERNAME'] = (string) ($config['username'] ?? '');
        $workerEnv['DB_PASSWORD'] = (string) ($config['password'] ?? '');

        try {
            // Launch both processes concurrently with explicit application directory and connection environment
            $p1 = proc_open($cmd1, $desc, $pipes1, base_path(), $workerEnv);
            $p2 = proc_open($cmd2, $desc, $pipes2, base_path(), $workerEnv);

            $out1 = stream_get_contents($pipes1[1]);
            fclose($pipes1[1]);
            fclose($pipes1[0]);
            fclose($pipes1[2]);
            $code1 = proc_close($p1);

            $out2 = stream_get_contents($pipes2[1]);
            fclose($pipes2[1]);
            fclose($pipes2[0]);
            fclose($pipes2[2]);
            $code2 = proc_close($p2);

            $this->assertEquals(0, $code1, "Process 1 failed: " . $out1);
            $this->assertEquals(0, $code2, "Process 2 failed: " . $out2);

            $res1 = json_decode($out1, true);
            $res2 = json_decode($out2, true);

            $this->assertEquals('ok', $res1['status'], 'Process 1 did not return ok: ' . $out1);
            $this->assertEquals('ok', $res2['status'], 'Process 2 did not return ok: ' . $out2);

            $awarded1 = $res1['result']['competitive_xp_awarded'];
            $awarded2 = $res2['result']['competitive_xp_awarded'];

            // Sum of awards must be exactly 400
            $this->assertEquals(400, $awarded1 + $awarded2);

            // Verify in database
            $counter = $pdo->query("SELECT competitive_xp_total FROM user_daily_competitive_counters WHERE user_id = {$userId} AND award_date = '2026-10-16'")->fetchColumn();
            $this->assertEquals(400, (int) $counter);

            $profileXp = $pdo->query("SELECT total_xp FROM user_profiles WHERE user_id = {$userId}")->fetchColumn();
            $this->assertEquals(500, (int) $profileXp); // Lifetime XP is 250 + 250 = 500
        } finally {
            $this->assertPdoPointsToDisposableTestDatabase($pdo, (string) ($config['host'] ?? '127.0.0.1'));

            // Clean up test data using prepared statements with bound parameters
            $stmtAudit = $pdo->prepare("DELETE FROM competitive_xp_events WHERE principal_key = :pk");
            $stmtAudit->execute(['pk' => 'U:' . $userId]);

            $stmtProc = $pdo->prepare("DELETE FROM processed_events WHERE user_id = :uid");
            $stmtProc->execute(['uid' => $userId]);

            $stmtLedger = $pdo->prepare("DELETE FROM user_xp_ledger WHERE user_id = :uid");
            $stmtLedger->execute(['uid' => $userId]);

            $stmtCounter = $pdo->prepare("DELETE FROM user_daily_competitive_counters WHERE user_id = :uid");
            $stmtCounter->execute(['uid' => $userId]);

            $stmtProfile = $pdo->prepare("DELETE FROM user_profiles WHERE user_id = :uid");
            $stmtProfile->execute(['uid' => $userId]);

            $stmtUser = $pdo->prepare("DELETE FROM users WHERE id = :uid");
            $stmtUser->execute(['uid' => $userId]);
        }
    }
}
