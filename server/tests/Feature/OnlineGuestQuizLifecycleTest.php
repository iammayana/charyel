<?php

namespace Tests\Feature;

use App\Exceptions\OnlineQuizException;
use App\Models\ActiveOnlineQuizSession;
use App\Models\GuestIdentity;
use App\Models\Question;
use App\Models\QuestionRevision;
use App\Models\User;
use App\Services\Quiz\OnlineQuizLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class OnlineGuestQuizLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();
    }

    /**
     * Helper to create an authenticated guest with contact info.
     */
    protected function createGuestWithContact(array $attributes = []): array
    {
        $guest = GuestIdentity::create(array_merge([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('secret_123', PASSWORD_BCRYPT),
            'email' => 'guest_' . Str::random(8) . '@example.com',
            'phone_number' => '+23480' . rand(10000000, 99999999),
            'completed_quiz_count' => 0,
            'gross_lifetime_xp' => 0,
            'created_at' => now(),
        ], $attributes));

        $token = $guest->createToken('guest_token', ['guest:play'])->plainTextToken;

        return ['guest' => $guest, 'token' => $token];
    }

    /**
     * Helper to create an authenticated guest without contact info.
     */
    protected function createGuestWithoutContact(): array
    {
        $guest = GuestIdentity::create([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('secret_123', PASSWORD_BCRYPT),
            'email' => null,
            'phone_number' => null,
            'completed_quiz_count' => 0,
            'gross_lifetime_xp' => 0,
            'created_at' => now(),
        ]);

        $token = $guest->createToken('guest_token', ['guest:play'])->plainTextToken;

        return ['guest' => $guest, 'token' => $token];
    }

    /**
     * Helper to seed published questions and revisions.
     *
     * @return array<int, array{question: Question, revision: QuestionRevision}>
     */
    protected function seedQuestions(int $count = 10, string $difficulty = 'medium', string $category = 'Gospels'): array
    {
        $questions = [];
        for ($i = 1; $i <= $count; $i++) {
            $qId = (string) Str::uuid7();
            $q = Question::create([
                'id' => $qId,
                'testament' => 'NT',
                'book' => 'Matthew',
                'chapter' => 1,
                'verse' => $i,
                'scripture_reference' => "Matthew 1:{$i}",
                'scripture_version' => 'KJV',
                'category' => $category,
                'difficulty' => $difficulty,
                'version' => 1,
                'is_published' => true,
                'is_withdrawn' => false,
            ]);

            $rev = QuestionRevision::create([
                'question_id' => $qId,
                'version' => 1,
                'question_text' => "Sample question text {$i} for {$category}?",
                'options' => [
                    ['id' => 'opt_a', 'text' => "Option A for question {$i}"],
                    ['id' => 'opt_b', 'text' => "Option B (correct) for question {$i}"],
                    ['id' => 'opt_c', 'text' => "Option C for question {$i}"],
                    ['id' => 'opt_d', 'text' => "Option D for question {$i}"],
                ],
                'correct_option_id' => 'opt_b',
                'explanation' => "Explanation for question {$i}",
            ]);

            $q->update(['current_revision_id' => $rev->id]);
            $questions[] = ['question' => $q, 'revision' => $rev];
        }

        return $questions;
    }

    // =========================================================================
    // 1. VALID ONLINE START AND COMPLETION LIFECYCLE
    // =========================================================================

    public function test_valid_online_start_returns_client_safe_questions(): void
    {
        $this->seedQuestions(10, 'medium', 'Gospels');
        $auth = $this->createGuestWithContact();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', [
                'quiz_mode' => 'standard',
                'category' => 'Gospels',
                'difficulty' => 'medium',
                'device_id' => 'dev_test_1',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'data' => [
                'session_id',
                'quiz_mode',
                'difficulty',
                'category',
                'started_at',
                'expires_at',
                'duration_seconds',
                'total_questions',
                'questions' => [
                    '*' => [
                        'question_id',
                        'version',
                        'question_text',
                        'options' => [
                            '*' => ['id', 'text'],
                        ],
                        'category',
                        'difficulty',
                        'scripture_reference',
                        'scripture_version',
                    ],
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals(10, $data['total_questions']);
        $this->assertCount(10, $data['questions']);

        // Assert client safety: NO correct_option_id or explanation leaked
        foreach ($data['questions'] as $q) {
            $this->assertArrayNotHasKey('correct_option_id', $q);
            $this->assertArrayNotHasKey('explanation', $q);
        }

        // Verify active session persisted in database
        $this->assertDatabaseHas('active_online_quiz_sessions', [
            'id' => $data['session_id'],
            'guest_identity_id' => $auth['guest']->id,
            'status' => 'active',
        ]);

        // Verify guest active pointer is set
        $this->assertEquals($data['session_id'], $auth['guest']->fresh()->active_quiz_session_id);
    }

    public function test_valid_online_completion_scores_answers_and_settles_xp(): void
    {
        $seeded = $this->seedQuestions(10, 'medium', 'Gospels');
        $auth = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', [
                'category' => 'Gospels',
                'difficulty' => 'medium',
            ]);

        $startRes->assertStatus(201);
        $sessionId = $startRes->json('data.session_id');
        $questions = $startRes->json('data.questions');

        // Prepare 10 correct answers ('opt_b' for all)
        $answers = [];
        foreach ($questions as $q) {
            $answers[] = [
                'question_id' => $q['question_id'],
                'selected_option_id' => 'opt_b',
                'time_taken_ms' => 1500,
            ];
        }

        $completeRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => $answers,
            ]);

        $completeRes->assertStatus(200);
        $completeRes->assertJsonStructure([
            'status',
            'data' => [
                'session_id',
                'total_questions',
                'correct_answers',
                'gross_xp_earned',
                'competitive_xp_awarded',
                'guest_lifetime_xp',
                'completed_quiz_count',
                'trial_limit_reached',
                'is_duplicate',
                'item_results',
            ],
        ]);

        $data = $completeRes->json('data');
        $this->assertEquals(10, $data['total_questions']);
        $this->assertEquals(10, $data['correct_answers']);
        // 10 medium questions * 15 XP = 150 gross XP
        $this->assertEquals(150, $data['gross_xp_earned']);
        $this->assertEquals(150, $data['competitive_xp_awarded']);
        $this->assertEquals(150, $data['guest_lifetime_xp']);
        $this->assertEquals(1, $data['completed_quiz_count']);
        $this->assertTrue($data['trial_limit_reached']); // 150 gross XP triggers trial limit

        // Verify quiz_sessions historical record
        $this->assertDatabaseHas('quiz_sessions', [
            'id' => $sessionId,
            'guest_identity_id' => $auth['guest']->id,
            'total_questions' => 10,
            'correct_answers' => 10,
            'xp_earned' => 150,
            'competitive_xp_awarded' => 150,
            'verification_status' => 'verified',
        ]);

        // Verify quiz_session_items count
        $this->assertEquals(10, DB::table('quiz_session_items')->where('session_id', $sessionId)->count());

        // Verify user_xp_ledger
        $this->assertDatabaseHas('user_xp_ledger', [
            'guest_identity_id' => $auth['guest']->id,
            'reward_source' => 'quiz_completion',
            'source_id' => $sessionId,
            'xp_amount' => 150,
        ]);

        // Verify competitive counters
        $this->assertDatabaseHas('user_daily_competitive_counters', [
            'guest_identity_id' => $auth['guest']->id,
            'competitive_xp_total' => 150,
        ]);

        // Verify active session marked completed and active pointer released
        $session = ActiveOnlineQuizSession::find($sessionId);
        $this->assertEquals('completed', $session->status);
        $this->assertNotNull($session->completed_at);
        $this->assertNull($session->active_presence);

        $freshGuest = $auth['guest']->fresh();
        $this->assertNull($freshGuest->active_quiz_session_id);
        $this->assertEquals(1, $freshGuest->completed_quiz_count);
        $this->assertEquals(150, $freshGuest->gross_lifetime_xp);
        $this->assertNotNull($freshGuest->trial_limit_reached_at);
    }

    // =========================================================================
    // 2. AUTHENTICATION & PRINCIPAL RESTRICTIONS
    // =========================================================================

    public function test_guest_without_contact_is_rejected_from_start_and_complete(): void
    {
        $this->seedQuestions(10);
        $auth = $this->createGuestWithoutContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);

        $startRes->assertStatus(403);
        $this->assertEquals('GUEST_CONTACT_REQUIRED', $startRes->json('code'));

        $completeRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => (string) Str::uuid7(),
                'answers' => [],
            ]);

        $completeRes->assertStatus(403);
        $this->assertEquals('GUEST_CONTACT_REQUIRED', $completeRes->json('code'));
    }

    public function test_registered_user_tokens_are_strictly_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('user_token', ['user:all'])->plainTextToken;

        // Registered user cannot start online guest quiz
        $resStart = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/quiz/online/start', []);
        $resStart->assertStatus(403);

        // Registered user cannot complete
        $resComplete = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => (string) Str::uuid7(),
                'answers' => [],
            ]);
        $resComplete->assertStatus(403);

        // Registered user cannot abandon
        $resAbandon = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/quiz/online/abandon', [
                'session_id' => (string) Str::uuid7(),
            ]);
        $resAbandon->assertStatus(403);

        // Registered user cannot check guest trial status
        $resStatus = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/quiz/online/trial-status');
        $resStatus->assertStatus(403);
    }

    public function test_claimed_guest_identity_is_rejected(): void
    {
        $user = User::factory()->create();
        $auth = $this->createGuestWithContact([
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);

        $res->assertStatus(403);
    }

    public function test_other_guest_cannot_complete_or_abandon_session(): void
    {
        $this->seedQuestions(10);
        $guestA = $this->createGuestWithContact();
        $guestB = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $guestA['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $sessionId = $startRes->json('data.session_id');

        // Guest B tries to complete Guest A's session
        $this->app['auth']->forgetGuards();
        $resComplete = $this->withHeader('Authorization', 'Bearer ' . $guestB['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => (string) Str::uuid7(), 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resComplete->assertStatus(403);
        $this->assertEquals('SESSION_FORBIDDEN', $resComplete->json('code'));

        // Guest B tries to abandon Guest A's session
        $resAbandon = $this->withHeader('Authorization', 'Bearer ' . $guestB['token'])
            ->postJson('/api/v1/quiz/online/abandon', [
                'session_id' => $sessionId,
            ]);
        $resAbandon->assertStatus(403);
        $this->assertEquals('SESSION_FORBIDDEN', $resAbandon->json('code'));
    }

    // =========================================================================
    // 3. OVERLAPPING & CONCURRENT ACTIVE SESSIONS
    // =========================================================================

    public function test_cannot_start_overlapping_active_online_session(): void
    {
        $this->seedQuestions(20);
        $auth = $this->createGuestWithContact();

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $res1->assertStatus(201);
        $session1Id = $res1->json('data.session_id');

        // Second start while first is still active MUST fail
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $res2->assertStatus(409);
        $this->assertEquals('ACTIVE_SESSION_EXISTS', $res2->json('code'));
        $this->assertEquals($session1Id, $res2->json('context.active_session_id'));
    }

    // =========================================================================
    // 4. QUESTION BANK & IMMUTABLE REVISION INTEGRITY
    // =========================================================================

    public function test_insufficient_published_questions_returns_422(): void
    {
        // Zero questions in 'Epistles'
        $auth = $this->createGuestWithContact();

        $res = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', [
                'category' => 'Epistles',
            ]);

        $res->assertStatus(422);
        $this->assertEquals('INSUFFICIENT_QUESTIONS', $res->json('code'));
    }

    public function test_immutable_revision_scoring_after_question_updates(): void
    {
        $qId = (string) Str::uuid7();
        $q = Question::create([
            'id' => $qId,
            'testament' => 'NT',
            'book' => 'John',
            'chapter' => 3,
            'verse' => 16,
            'scripture_reference' => 'John 3:16',
            'category' => 'Gospels',
            'difficulty' => 'easy',
            'version' => 1,
            'is_published' => true,
        ]);

        $rev1 = QuestionRevision::create([
            'question_id' => $qId,
            'version' => 1,
            'question_text' => 'For God so loved the world that He gave His only begotten Son...',
            'options' => [
                ['id' => 'opt_1', 'text' => 'Moses'],
                ['id' => 'opt_2', 'text' => 'His only begotten Son'],
            ],
            'correct_option_id' => 'opt_2',
        ]);
        $q->update(['current_revision_id' => $rev1->id]);

        $auth = $this->createGuestWithContact();

        // 1. Guest starts session bound to Revision 1
        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', [
                'category' => 'Gospels',
                'question_count' => 1,
            ]);
        $startRes->assertStatus(201);
        $sessionId = $startRes->json('data.session_id');

        // 2. Admin updates question to Revision 2 with a new text and changed correct option!
        $rev2 = QuestionRevision::create([
            'question_id' => $qId,
            'version' => 2,
            'question_text' => 'Updated question text for John 3:16',
            'options' => [
                ['id' => 'opt_alpha', 'text' => 'Alpha'],
                ['id' => 'opt_omega', 'text' => 'Omega'],
            ],
            'correct_option_id' => 'opt_omega',
        ]);
        $q->update(['current_revision_id' => $rev2->id, 'version' => 2]);

        // 3. Guest submits answer using options from Revision 1 ('opt_2')
        $completeRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $qId, 'selected_option_id' => 'opt_2'],
                ],
            ]);

        $completeRes->assertStatus(200);
        $this->assertEquals(1, $completeRes->json('data.correct_answers'));
        $this->assertEquals(10, $completeRes->json('data.gross_xp_earned')); // Easy question = 10 XP
    }

    public function test_answer_tampering_and_unauthorized_questions_are_rejected(): void
    {
        $this->seedQuestions(2, 'easy', 'Gospels');
        $auth = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 2]);
        $startRes->assertStatus(201);
        $sessionId = $startRes->json('data.session_id');
        $questions = $startRes->json('data.questions');

        // 1. Unauthorized question ID
        $resTampered = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => (string) Str::uuid7(), 'selected_option_id' => 'opt_b'],
                    ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resTampered->assertStatus(422);
        $this->assertEquals('UNAUTHORIZED_QUESTION', $resTampered->json('code'));

        // 2. Duplicate question submission
        $resDuplicate = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resDuplicate->assertStatus(422);
        $this->assertEquals('DUPLICATE_QUESTION', $resDuplicate->json('code'));

        // 3. Count mismatch (submitted 1 answer for 2 questions)
        $resMismatch = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resMismatch->assertStatus(422);
        $this->assertEquals('COUNT_MISMATCH', $resMismatch->json('code'));

        // 4. Invalid non-existent option ID
        $resInvalidOption = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_nonexistent'],
                    ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resInvalidOption->assertStatus(422);
        $this->assertEquals('INVALID_OPTION', $resInvalidOption->json('code'));
    }

    // =========================================================================
    // 5. TRIAL LIMITS & PROGRESSION BOUNDARIES
    // =========================================================================

    public function test_trial_limits_reject_fourth_quiz(): void
    {
        $this->seedQuestions(10);

        // Guest with 3 completed quizzes cannot start 4th quiz
        $authCountLimit = $this->createGuestWithContact(['completed_quiz_count' => 3]);
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $authCountLimit['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $res1->assertStatus(403);
        $this->assertEquals('TRIAL_QUIZ_LIMIT_REACHED', $res1->json('code'));
    }

    public function test_trial_limits_reject_guest_at_or_above_150_gross_xp(): void
    {
        $this->seedQuestions(10);

        // Guest with 150 gross XP cannot start another quiz
        $authXpLimit = $this->createGuestWithContact(['gross_lifetime_xp' => 150]);
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $authXpLimit['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $res2->assertStatus(403);
        $this->assertEquals('TRIAL_XP_LIMIT_REACHED', $res2->json('code'));
    }

    public function test_authorized_completion_can_legitimately_cross_150_xp_boundary(): void
    {
        $this->seedQuestions(2, 'hard'); // 2 hard questions * 20 XP = 40 XP
        $auth = $this->createGuestWithContact([
            'completed_quiz_count' => 1,
            'gross_lifetime_xp' => 130, // 130 + 40 = 170 XP
        ]);

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 2]);
        $startRes->assertStatus(201);
        $sessionId = $startRes->json('data.session_id');
        $questions = $startRes->json('data.questions');

        $completeRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
                    ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);

        $completeRes->assertStatus(200);
        $data = $completeRes->json('data');
        $this->assertEquals(40, $data['gross_xp_earned']);
        $this->assertEquals(170, $data['guest_lifetime_xp']); // Crossing 150 XP allowed!
        $this->assertTrue($data['trial_limit_reached']);
        $this->assertNotNull($data['trial_limit_reached_at']);

        // Next start must now be rejected
        $nextStart = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);
        $nextStart->assertStatus(403);
    }

    public function test_shared_offline_reservation_capacity_blocks_online_start(): void
    {
        $this->seedQuestions(10);
        $auth = $this->createGuestWithContact(['completed_quiz_count' => 1]);

        // Insert 2 unexpired offline quiz authorizations
        DB::table('offline_quiz_authorizations')->insert([
            [
                'ticket_id' => (string) Str::uuid7(),
                'guest_identity_id' => $auth['guest']->id,
                'slot_number' => 1,
                'generation' => 1,
                'manifest_payload' => json_encode(['dummy' => 1]),
                'manifest_hash' => hash('sha256', 'payload1'),
                'signature' => 'sig1',
                'key_id' => 'k1',
                'status' => 'issued',
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
            ],
            [
                'ticket_id' => (string) Str::uuid7(),
                'guest_identity_id' => $auth['guest']->id,
                'slot_number' => 2,
                'generation' => 1,
                'manifest_payload' => json_encode(['dummy' => 2]),
                'manifest_hash' => hash('sha256', 'payload2'),
                'signature' => 'sig2',
                'key_id' => 'k2',
                'status' => 'issued',
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
            ],
        ]);

        // Total allocated = 1 completed + 2 unexpired offline = 3
        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', []);

        $startRes->assertStatus(403);
        $this->assertEquals('TRIAL_CAPACITY_EXHAUSTED', $startRes->json('code'));
    }

    // =========================================================================
    // 6. IDEMPOTENCY, REPLAYS & CONFLICT REJECTION
    // =========================================================================

    public function test_duplicate_identical_completion_returns_cached_response_without_double_xp(): void
    {
        $this->seedQuestions(2, 'easy');
        $auth = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 2]);
        $sessionId = $startRes->json('data.session_id');
        $questions = $startRes->json('data.questions');

        $answers = [
            ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
            ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
        ];

        // First completion
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => $answers,
            ]);
        $res1->assertStatus(200);
        $this->assertFalse($res1->json('data.is_duplicate'));
        $this->assertEquals(20, $res1->json('data.gross_xp_earned'));

        // Identical replay submission
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => $answers,
            ]);
        $res2->assertStatus(200);
        $this->assertTrue($res2->json('data.is_duplicate'));

        // Assert exactly-once accounting invariants
        $this->assertEquals(1, DB::table('quiz_sessions')->where('id', $sessionId)->count());
        $this->assertEquals(1, DB::table('user_xp_ledger')->where('source_id', $sessionId)->count());
        $this->assertEquals(20, $auth['guest']->fresh()->gross_lifetime_xp);
        $this->assertEquals(1, $auth['guest']->fresh()->completed_quiz_count);
    }

    public function test_conflicting_completion_retry_is_rejected(): void
    {
        $this->seedQuestions(2, 'easy');
        $auth = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 2]);
        $sessionId = $startRes->json('data.session_id');
        $questions = $startRes->json('data.questions');

        // Complete with answer 1
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_b'],
                    ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $res1->assertStatus(200);

        // Try to resubmit with conflicting answer!
        $resConflict = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => $questions[0]['question_id'], 'selected_option_id' => 'opt_a'],
                    ['question_id' => $questions[1]['question_id'], 'selected_option_id' => 'opt_b'],
                ],
            ]);
        $resConflict->assertStatus(409);
        $this->assertEquals('SESSION_ALREADY_COMPLETED', $resConflict->json('code'));
    }

    // =========================================================================
    // 7. ABANDONMENT, EXPIRATION & LATE SUBMISSIONS
    // =========================================================================

    public function test_abandon_active_session_releases_reservation_and_awards_zero_xp(): void
    {
        $this->seedQuestions(5);
        $auth = $this->createGuestWithContact();

        $startRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 5]);
        $sessionId = $startRes->json('data.session_id');

        $abandonRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/abandon', [
                'session_id' => $sessionId,
            ]);

        $abandonRes->assertStatus(200);
        $this->assertEquals('abandoned', $abandonRes->json('data.status'));

        // Guest pointer is released
        $freshGuest = $auth['guest']->fresh();
        $this->assertNull($freshGuest->active_quiz_session_id);
        $this->assertEquals(0, $freshGuest->completed_quiz_count);
        $this->assertEquals(0, $freshGuest->gross_lifetime_xp);

        // Active session row is terminal
        $session = ActiveOnlineQuizSession::find($sessionId);
        $this->assertEquals('abandoned', $session->status);
        $this->assertNotNull($session->abandoned_at);
        $this->assertNull($session->active_presence);

        // Late completion on abandoned session must be rejected
        $lateComplete = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => 'dummy', 'selected_option_id' => 'opt_dummy'],
                ],
            ]);
        $lateComplete->assertStatus(409);
        $this->assertEquals('SESSION_ABANDONED', $lateComplete->json('code'));

        // Guest can now start a new quiz
        $newStart = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 5]);
        $newStart->assertStatus(201);
    }

    public function test_expired_session_cannot_be_completed_and_stale_session_is_swept(): void
    {
        $this->seedQuestions(2);
        $auth = $this->createGuestWithContact();

        $sessionId = (string) Str::uuid7();
        $started = now()->subMinutes(35);
        $expires = now()->subMinutes(5);

        // Create expired session directly
        ActiveOnlineQuizSession::create([
            'id' => $sessionId,
            'guest_identity_id' => $auth['guest']->id,
            'quiz_mode' => 'standard',
            'category' => 'Gospels',
            'difficulty' => 'medium',
            'device_id' => 'dev_exp',
            'manifest_payload' => ['items' => []],
            'manifest_hash' => hash('sha256', 'p'),
            'status' => 'active',
            'started_at' => $started,
            'expires_at' => $expires,
        ]);
        $auth['guest']->update(['active_quiz_session_id' => $sessionId]);

        // 1. Completion attempt on expired session must fail
        $completeRes = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/complete', [
                'session_id' => $sessionId,
                'answers' => [
                    ['question_id' => 'dummy', 'selected_option_id' => 'opt_dummy'],
                ],
            ]);
        $completeRes->assertStatus(422);
        $this->assertEquals('SESSION_EXPIRED', $completeRes->json('code'));

        // 2. Active pointer is cleared and session status transitioned to expired
        $session = ActiveOnlineQuizSession::find($sessionId);
        $this->assertEquals('expired', $session->status);
        $this->assertNotNull($session->expired_at);
        $this->assertNull($auth['guest']->fresh()->active_quiz_session_id);

        // 3. New start succeeds because expired session was swept
        $newStart = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/quiz/online/start', ['question_count' => 2]);
        $newStart->assertStatus(201);
    }

    // =========================================================================
    // 8. TRIAL STATUS ENDPOINT
    // =========================================================================

    public function test_trial_status_endpoint_returns_accurate_capacities(): void
    {
        $auth = $this->createGuestWithContact([
            'completed_quiz_count' => 1,
            'gross_lifetime_xp' => 45,
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/quiz/online/trial-status');

        $res->assertStatus(200);
        $res->assertJson([
            'status' => 'success',
            'data' => [
                'guest_uuid' => $auth['guest']->guest_uuid,
                'completed_quiz_count' => 1,
                'max_quiz_count' => 3,
                'remaining_quiz_opportunities' => 2,
                'gross_lifetime_xp' => 45,
                'max_lifetime_xp' => 150,
                'remaining_xp_capacity' => 105,
                'has_contact' => true,
                'is_claimed' => false,
                'trial_limit_reached' => false,
            ],
        ]);
    }
}
