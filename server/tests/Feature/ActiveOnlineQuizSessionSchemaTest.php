<?php

namespace Tests\Feature;

use App\Exceptions\ScoringValidationException;
use App\Models\ActiveOnlineQuizSession;
use App\Models\Question;
use App\Models\QuestionRevision;
use App\Services\Quiz\AuthoritativeScoringService;
use App\Services\Quiz\Policies\StandardQuizScoringPolicy;
use App\Services\Quiz\QuestionBankService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class ActiveOnlineQuizSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Helper to create a guest identity.
     */
    protected function createGuest(array $attributes = []): int
    {
        return DB::table('guest_identities')->insertGetId(array_merge([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('guest_secret_123', PASSWORD_BCRYPT),
            'email' => 'guest_' . Str::random(6) . '@example.com',
            'created_at' => now(),
        ], $attributes));
    }

    /**
     * Helper to build a sample manifest payload.
     */
    protected function sampleManifestPayload(): array
    {
        return [
            'manifest_id' => (string) Str::uuid7(),
            'quiz_mode' => 'standard',
            'difficulty' => 'medium',
            'category' => 'Acts & Early Church',
            'items' => [
                [
                    'question_id' => (string) Str::uuid7(),
                    'revision_id' => 101,
                    'version' => 1,
                    'difficulty' => 'medium',
                    'category' => 'Acts & Early Church',
                ],
                [
                    'question_id' => (string) Str::uuid7(),
                    'revision_id' => 102,
                    'version' => 1,
                    'difficulty' => 'easy',
                    'category' => 'Acts & Early Church',
                ],
            ],
        ];
    }

    // =========================================================================
    // 1. MIGRATION PRESENCE & SCHEMA INTEGRITY
    // =========================================================================

    public function test_active_online_quiz_sessions_table_exists(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('active_online_quiz_sessions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('active_online_quiz_sessions', [
            'id',
            'guest_identity_id',
            'quiz_mode',
            'category',
            'difficulty',
            'device_id',
            'manifest_payload',
            'manifest_hash',
            'status',
            'started_at',
            'expires_at',
            'completed_at',
            'abandoned_at',
            'expired_at',
            'created_at',
            'active_presence',
        ]));
    }

    public function test_active_online_session_can_be_created_with_manifest(): void
    {
        $guestId = $this->createGuest();
        $sessionId = (string) Str::uuid7();
        $payload = $this->sampleManifestPayload();
        $hash = hash('sha256', json_encode($payload));

        $session = ActiveOnlineQuizSession::create([
            'id' => $sessionId,
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'category' => 'Acts & Early Church',
            'difficulty' => 'medium',
            'device_id' => 'device_abc_123',
            'manifest_payload' => $payload,
            'manifest_hash' => $hash,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertDatabaseHas('active_online_quiz_sessions', [
            'id' => $sessionId,
            'guest_identity_id' => $guestId,
            'status' => 'active',
            'manifest_hash' => $hash,
        ]);

        $retrieved = ActiveOnlineQuizSession::find($sessionId);
        $this->assertNotNull($retrieved);
        $this->assertEquals($payload, $retrieved->manifest_payload);
        $this->assertTrue($retrieved->isActive());
        $this->assertFalse($retrieved->isTerminal());
    }

    // =========================================================================
    // 2. FOREIGN KEY INTEGRITY & DELETION RESTRICTION
    // =========================================================================

    public function test_session_creation_rejects_non_existent_guest_identity(): void
    {
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => 9999999, // Non-existent foreign key
            'quiz_mode' => 'standard',
            'category' => 'Gospels',
            'difficulty' => 'easy',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'payload'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_deleting_guest_identity_with_sessions_is_restricted(): void
    {
        $guestId = $this->createGuest();

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->expectException(QueryException::class);
        // ON DELETE RESTRICT prevents deleting guest_identities
        DB::table('guest_identities')->where('id', $guestId)->delete();
    }

    // =========================================================================
    // 3. AT MOST ONE ACTIVE SESSION PER GUEST (DATABASE CONSTRAINT)
    // =========================================================================

    public function test_database_enforces_at_most_one_active_session_per_guest(): void
    {
        $guestId = $this->createGuest();

        // First active session succeeds
        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);

        // Second active session for the same guest identity MUST fail unique constraint
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_2',
            'manifest_payload' => ['q' => 2],
            'manifest_hash' => hash('sha256', 'p2'),
            'status' => 'active', // Duplicate active session!
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_multiple_guests_can_each_hold_an_active_session_simultaneously(): void
    {
        $guest1 = $this->createGuest();
        $guest2 = $this->createGuest();

        $session1 = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guest1,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);

        $session2 = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guest2,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_2',
            'manifest_payload' => ['q' => 2],
            'manifest_hash' => hash('sha256', 'p2'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertNotEquals($session1->id, $session2->id);
        $this->assertEquals(2, ActiveOnlineQuizSession::where('status', 'active')->count());
    }

    // =========================================================================
    // 4. MULTIPLE RETAINED HISTORICAL TERMINAL SESSIONS
    // =========================================================================

    public function test_multiple_historical_terminal_sessions_can_coexist_for_same_guest(): void
    {
        $guestId = $this->createGuest();

        // 1. Completed session (active_presence = NULL)
        $completedSession = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'completed',
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(),
        ]);

        // 2. Abandoned session (active_presence = NULL)
        $abandonedSession = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 2],
            'manifest_hash' => hash('sha256', 'p2'),
            'status' => 'abandoned',
            'expires_at' => now()->addMinutes(30),
            'abandoned_at' => now(),
        ]);

        // 3. Expired session (active_presence = NULL, expires_at > started_at)
        $expiredSession = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 3],
            'manifest_hash' => hash('sha256', 'p3'),
            'status' => 'expired',
            'started_at' => now()->subMinutes(35),
            'expires_at' => now()->subMinutes(5),
            'expired_at' => now(),
        ]);

        // 4. Newly started active session (active_presence = 1)
        $activeSession = ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 4],
            'manifest_hash' => hash('sha256', 'p4'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertEquals(4, ActiveOnlineQuizSession::where('guest_identity_id', $guestId)->count());
        $this->assertEquals(1, ActiveOnlineQuizSession::where('guest_identity_id', $guestId)->where('status', 'active')->count());
        $this->assertTrue($completedSession->isTerminal());
        $this->assertTrue($abandonedSession->isTerminal());
        $this->assertTrue($expiredSession->isTerminal());
        $this->assertFalse($activeSession->isTerminal());
    }

    // =========================================================================
    // 5. LIFECYCLE STATUS & TERMINAL TIMESTAMPS INTEGRITY (MYSQL CHECKS)
    // =========================================================================

    public function test_mysql_check_constraint_rejects_active_session_with_completed_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // An active session must have NULL completed_at, abandoned_at, and expired_at
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'active',
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(), // Invalid for status = 'active'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_completed_session_without_completed_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // A completed session must have a non-null completed_at
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'completed',
            'expires_at' => now()->addMinutes(30),
            'completed_at' => null, // Invalid for status = 'completed'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_completed_session_with_abandoned_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // A completed session cannot have an abandoned_at timestamp
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'completed',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(),
            'abandoned_at' => now(), // Invalid for status = 'completed'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_completed_session_with_expired_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // A completed session cannot have an expired_at timestamp
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'completed',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'completed_at' => now(),
            'expired_at' => now(), // Invalid for status = 'completed'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_abandoned_session_with_completed_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // An abandoned session cannot have a completed_at timestamp
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'abandoned',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'abandoned_at' => now(),
            'completed_at' => now(), // Invalid for status = 'abandoned'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_expired_session_with_completed_timestamp(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // An expired session cannot have a completed_at timestamp
        $this->expectException(QueryException::class);

        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'expired',
            'started_at' => now()->subMinutes(35),
            'expires_at' => now()->subMinutes(5),
            'expired_at' => now(),
            'completed_at' => now(), // Invalid for status = 'expired'!
        ]);
    }

    public function test_mysql_check_constraint_rejects_session_where_expires_at_is_not_greater_than_started_at(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints enforced on MySQL');
        }

        $guestId = $this->createGuest();

        // expires_at equal to started_at must be rejected
        $this->expectException(QueryException::class);

        $now = now();
        ActiveOnlineQuizSession::create([
            'id' => (string) Str::uuid7(),
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'device_id' => 'dev_1',
            'manifest_payload' => ['q' => 1],
            'manifest_hash' => hash('sha256', 'p1'),
            'status' => 'active',
            'started_at' => $now,
            'expires_at' => $now, // Invalid: expires_at must be strictly greater than started_at!
        ]);
    }

    // =========================================================================
    // 6. CANONICAL DIFFICULTY VOCABULARY & SCORING COMPATIBILITY
    // =========================================================================

    public function test_difficulty_values_are_canonically_compatible_with_question_bank_and_scoring_policy(): void
    {
        $guestId = $this->createGuest();

        // 1. Verify canonical values can be persisted without conversion
        $canonicalDifficulties = [
            ActiveOnlineQuizSession::DIFFICULTY_EASY,
            ActiveOnlineQuizSession::DIFFICULTY_MEDIUM,
            ActiveOnlineQuizSession::DIFFICULTY_HARD,
            ActiveOnlineQuizSession::DIFFICULTY_MIXED,
        ];

        foreach ($canonicalDifficulties as $diff) {
            $session = ActiveOnlineQuizSession::create([
                'id' => (string) Str::uuid7(),
                'guest_identity_id' => $guestId,
                'quiz_mode' => 'standard',
                'difficulty' => $diff,
                'device_id' => 'dev_1',
                'manifest_payload' => ['q' => 1],
                'manifest_hash' => hash('sha256', $diff),
                'status' => 'completed',
                'started_at' => now(),
                'expires_at' => now()->addMinutes(30),
                'completed_at' => now(),
            ]);

            $this->assertEquals($diff, $session->difficulty);
        }

        // 2. Verify non-canonical difficulty like 'beginner' or 'advanced' is rejected at DB level
        if (DB::getDriverName() === 'mysql') {
            try {
                ActiveOnlineQuizSession::create([
                    'id' => (string) Str::uuid7(),
                    'guest_identity_id' => $guestId,
                    'quiz_mode' => 'standard',
                    'difficulty' => 'beginner', // Non-canonical!
                    'device_id' => 'dev_1',
                    'manifest_payload' => ['q' => 1],
                    'manifest_hash' => hash('sha256', 'beginner'),
                    'status' => 'completed',
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                    'completed_at' => now(),
                ]);
                $this->fail('Expected QueryException for non-canonical difficulty "beginner" on MySQL.');
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }

        // 3. Verify StandardQuizScoringPolicy exactly maps each individual question difficulty
        $policy = new StandardQuizScoringPolicy();
        $this->assertEquals(10, $policy->calculateQuestionXp('easy', true));
        $this->assertEquals(15, $policy->calculateQuestionXp('medium', true));
        $this->assertEquals(20, $policy->calculateQuestionXp('hard', true));
        $this->assertEquals(0, $policy->calculateQuestionXp('hard', false));
    }

    // =========================================================================
    // 7. MANIFEST PERSISTENCE, RECONSTRUCTION & AUTHORITATIVE SCORING INTEGRITY
    // =========================================================================

    public function test_stored_manifest_payload_can_be_reconstructed_and_authoritatively_scored(): void
    {
        $guestId = $this->createGuest();

        // 1. Seed two published questions with immutable revisions in DB
        $q1Id = (string) Str::uuid7();
        $q1 = Question::create([
            'id' => $q1Id,
            'testament' => 'NT',
            'book' => 'Acts',
            'chapter' => 2,
            'verse' => 1,
            'scripture_reference' => 'Acts 2:1',
            'category' => 'Acts & Early Church',
            'difficulty' => 'medium',
            'version' => 1,
            'is_published' => true,
        ]);
        $rev1 = QuestionRevision::create([
            'question_id' => $q1Id,
            'version' => 1,
            'question_text' => 'When the Day of Pentecost had fully come, they were all with one accord in one place.',
            'options' => [
                ['id' => 'opt_a', 'text' => 'Passover'],
                ['id' => 'opt_b', 'text' => 'Pentecost'],
            ],
            'correct_option_id' => 'opt_b',
        ]);
        $q1->update(['current_revision_id' => $rev1->id]);

        $q2Id = (string) Str::uuid7();
        $q2 = Question::create([
            'id' => $q2Id,
            'testament' => 'NT',
            'book' => 'Acts',
            'chapter' => 9,
            'verse' => 3,
            'scripture_reference' => 'Acts 9:3',
            'category' => 'Acts & Early Church',
            'difficulty' => 'hard',
            'version' => 1,
            'is_published' => true,
        ]);
        $rev2 = QuestionRevision::create([
            'question_id' => $q2Id,
            'version' => 1,
            'question_text' => 'As he journeyed he came near Damascus, and suddenly a light shone around him from heaven.',
            'options' => [
                ['id' => 'opt_1', 'text' => 'Jerusalem'],
                ['id' => 'opt_2', 'text' => 'Damascus'],
            ],
            'correct_option_id' => 'opt_2',
        ]);
        $q2->update(['current_revision_id' => $rev2->id]);

        // 2. Build manifest via QuestionBankService
        $bankService = new QuestionBankService();
        $manifest = $bankService->buildManifest([$q1->fresh('currentRevision'), $q2->fresh('currentRevision')], 'standard', 'mixed', 'Acts & Early Church');

        $manifestPayload = [
            'manifest_id' => $manifest->manifestId,
            'quiz_mode' => $manifest->quizMode,
            'difficulty' => $manifest->difficulty,
            'category' => $manifest->category,
            'items' => array_map(fn($item) => $item->toArray(), $manifest->items),
        ];
        $manifestHash = hash('sha256', json_encode($manifestPayload));

        // 3. Persist into active_online_quiz_sessions
        $sessionId = (string) Str::uuid7();
        $session = ActiveOnlineQuizSession::create([
            'id' => $sessionId,
            'guest_identity_id' => $guestId,
            'quiz_mode' => 'standard',
            'category' => 'Acts & Early Church',
            'difficulty' => 'mixed',
            'device_id' => 'dev_manifest_test',
            'manifest_payload' => $manifestPayload,
            'manifest_hash' => $manifestHash,
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        // 4. Retrieve session and reconstruct AuthorizedQuestionManifest from DB
        $retrievedSession = ActiveOnlineQuizSession::findOrFail($sessionId);
        $reconstructedManifest = $retrievedSession->toAuthorizedManifest();

        $this->assertEquals($manifest->manifestId, $reconstructedManifest->manifestId);
        $this->assertEquals(2, $reconstructedManifest->count());
        $this->assertEquals([$q1Id, $q2Id], $reconstructedManifest->getOrderedQuestionIds());

        // 5. Score valid answers authoritatively
        $scoringService = new AuthoritativeScoringService(new StandardQuizScoringPolicy());
        $answers = [
            ['question_id' => $q1Id, 'selected_option_id' => 'opt_b'], // correct (medium = 15 XP)
            ['question_id' => $q2Id, 'selected_option_id' => 'opt_2'], // correct (hard = 20 XP)
        ];

        $result = $scoringService->scoreAnswers($reconstructedManifest, $answers);
        $this->assertEquals(2, $result->correctAnswers);
        $this->assertEquals(2, $result->totalQuestions);
        $this->assertEquals(35, $result->grossXp); // 15 + 20 = 35 XP

        // 6. Security proof: Client CANNOT submit unauthorized question IDs
        $tamperedAnswers = [
            ['question_id' => (string) Str::uuid7(), 'selected_option_id' => 'opt_b'],
            ['question_id' => $q2Id, 'selected_option_id' => 'opt_2'],
        ];

        try {
            $scoringService->scoreAnswers($reconstructedManifest, $tamperedAnswers);
            $this->fail('Expected ScoringValidationException for unauthorized question ID.');
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_UNAUTHORIZED_QUESTION, $e->errorCode);
        }
    }

    // =========================================================================
    // 8. ORIGINAL COMPLETED-SESSION LEDGER PRESERVATION
    // =========================================================================

    public function test_original_quiz_sessions_and_items_remain_completely_unmodified(): void
    {
        // Assert table presence
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('quiz_sessions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('quiz_session_items'));

        // Assert quiz_sessions has NOT NULL completed_at intact
        $guestId = $this->createGuest();
        $sessionId = (string) Str::uuid7();

        // Inserting a valid completed historical session into original table succeeds
        DB::table('quiz_sessions')->insert([
            'id' => $sessionId,
            'user_id' => null,
            'guest_identity_id' => $guestId,
            'church_id' => null,
            'device_id' => 'dev_original',
            'quiz_mode' => 'standard',
            'category' => 'Gospels',
            'difficulty' => 'medium',
            'total_questions' => 10,
            'correct_answers' => 8,
            'claimed_score' => 80,
            'verified_score' => 80,
            'xp_earned' => 80,
            'competitive_xp_awarded' => 80,
            'duration_seconds' => 120,
            'completed_at' => now(),
            'verification_status' => 'verified',
        ]);

        $this->assertDatabaseHas('quiz_sessions', ['id' => $sessionId, 'guest_identity_id' => $guestId]);
    }

    // =========================================================================
    // 9. MIGRATION ROLLBACK AND RE-APPLICATION
    // =========================================================================

    public function test_migration_14_can_rollback_and_reapply_independently(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('active_online_quiz_sessions'));

        // Roll back ONLY Migration 14 (--step => 1)
        $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode);

        // Confirm active_online_quiz_sessions was dropped
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('active_online_quiz_sessions'));

        // Confirm Migrations 1-13 tables remain fully intact
        $tables1to13 = [
            'guest_claim_daily_attributions',
            'guest_identity_claims',
            'offline_quiz_authorizations',
            'contact_verification_tokens',
            'competitive_xp_events',
            'personal_access_tokens',
            'user_daily_competitive_counters',
            'quiz_sessions',
            'quiz_session_items',
            'question_revisions',
            'questions',
            'guest_identities',
            'users',
        ];

        foreach ($tables1to13 as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "Table {$table} must remain intact after rolling back Migration 14.");
        }

        // Re-apply Migration 14
        $reapplyExitCode = Artisan::call('migrate');
        $this->assertEquals(0, $reapplyExitCode);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('active_online_quiz_sessions'));
    }
}
