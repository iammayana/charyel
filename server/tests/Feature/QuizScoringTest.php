<?php

namespace Tests\Feature;

use App\Contracts\QuizScoringPolicyContract;
use App\Exceptions\ScoringValidationException;
use App\Models\GuestIdentity;
use App\Models\Question;
use App\Models\QuestionRevision;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Quiz\AuthoritativeScoringService;
use App\Services\Quiz\Dto\AuthorizedQuestionManifest;
use App\Services\Quiz\Policies\StandardQuizScoringPolicy;
use App\Services\Quiz\QuestionBankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

class QuizScoringTest extends TestCase
{
    use RefreshDatabase;

    protected QuestionBankService $bankService;
    protected AuthoritativeScoringService $scoringService;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

        $this->bankService = $this->app->make(QuestionBankService::class);
        $this->scoringService = $this->app->make(AuthoritativeScoringService::class);
    }

    /**
     * Helper to create a fully-wired question with an immutable revision.
     *
     * @return array{question: Question, revision: QuestionRevision}
     */
    protected function createQuestionWithRevision(array $questionOverrides = [], array $revisionOverrides = []): array
    {
        $questionId = (string) Str::uuid7();

        $question = Question::create(array_merge([
            'id' => $questionId,
            'testament' => 'NT',
            'book' => 'Acts',
            'chapter' => 17,
            'verse' => 11,
            'scripture_reference' => 'Acts 17:11',
            'scripture_version' => 'KJV',
            'category' => 'Acts & Early Church',
            'difficulty' => 'medium',
            'version' => 1,
            'current_revision_id' => null,
            'is_published' => true,
            'is_withdrawn' => false,
        ], $questionOverrides));

        $revision = QuestionRevision::create(array_merge([
            'question_id' => $questionId,
            'version' => 1,
            'question_text' => 'What did the Bereans examine daily?',
            'options' => [
                ['id' => 'opt_a', 'text' => 'The Scriptures'],
                ['id' => 'opt_b', 'text' => 'Roman Laws'],
                ['id' => 'opt_c', 'text' => 'Greek Philosophy'],
                ['id' => 'opt_d', 'text' => 'Trade Records'],
            ],
            'correct_option_id' => 'opt_a',
            'explanation' => 'Acts 17:11 notes they searched the scriptures daily.',
            'change_summary' => 'Initial revision',
            'created_by_user_id' => null,
            'created_at' => now(),
        ], $revisionOverrides));

        $question->update(['current_revision_id' => $revision->id]);
        $question->setRelation('currentRevision', $revision);

        return ['question' => $question, 'revision' => $revision];
    }

    // =========================================================================
    // 1. QUESTION SELECTION & REVISION RESOLUTION
    // =========================================================================

    public function test_select_questions_retrieves_only_published_active_questions(): void
    {
        // 1. Published valid question
        $pair1 = $this->createQuestionWithRevision(['category' => 'Gospels', 'difficulty' => 'easy']);

        // 2. Unpublished question (is_published = false)
        $this->createQuestionWithRevision(['is_published' => false]);

        // 3. Withdrawn question (is_withdrawn = true)
        $this->createQuestionWithRevision(['is_withdrawn' => true]);

        // 4. Soft-deleted question
        $pair4 = $this->createQuestionWithRevision();
        $pair4['question']->delete();

        // 5. Question lacking current_revision_id
        Question::create([
            'id' => (string) Str::uuid7(),
            'testament' => 'NT',
            'book' => 'Romans',
            'scripture_reference' => 'Romans 1:1',
            'scripture_version' => 'KJV',
            'category' => 'Epistles',
            'difficulty' => 'hard',
            'version' => 1,
            'current_revision_id' => null,
            'is_published' => true,
            'is_withdrawn' => false,
        ]);

        $manifest = $this->bankService->selectQuestions(1, ['category' => 'Gospels']);

        $this->assertEquals(1, $manifest->count());
        $this->assertEquals($pair1['question']->id, $manifest->getOrderedQuestionIds()[0]);
    }

    public function test_selection_resolves_exact_current_revision_when_multiple_revisions_exist(): void
    {
        $pair = $this->createQuestionWithRevision();
        $question = $pair['question'];

        // Add a second revision with updated text and different correct option
        $revision2 = QuestionRevision::create([
            'question_id' => $question->id,
            'version' => 2,
            'question_text' => 'Updated Berean question text?',
            'options' => [
                ['id' => 'opt_x', 'text' => 'Hebrew Scrolls'],
                ['id' => 'opt_y', 'text' => 'Oral Traditions'],
            ],
            'correct_option_id' => 'opt_x',
            'explanation' => 'Updated explanation.',
            'created_at' => now(),
        ]);

        // Point question to revision 2
        $question->update(['current_revision_id' => $revision2->id, 'version' => 2]);

        $manifest = $this->bankService->selectQuestions(1);
        $item = $manifest->getItem($question->id);

        $this->assertNotNull($item);
        $this->assertEquals($revision2->id, $item->revisionId);
        $this->assertEquals(2, $item->version);
        $this->assertTrue($item->revision->isCorrect('opt_x'));
        $this->assertFalse($item->revision->isCorrect('opt_a'));
    }

    public function test_select_questions_throws_when_insufficient_questions_exist(): void
    {
        $this->createQuestionWithRevision(); // only 1 exists

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient published questions available (found 1, requested 5).');

        $this->bankService->selectQuestions(5);
    }

    // =========================================================================
    // 2. SANITIZATION & ZERO DATA LEAKAGE
    // =========================================================================

    public function test_client_safe_manifest_representation_never_leaks_answers_or_secrets(): void
    {
        $pair = $this->createQuestionWithRevision();
        $manifest = $this->bankService->buildManifest([$pair['question']]);

        $clientPayload = $manifest->toClientSafeArray();

        $this->assertCount(1, $clientPayload);
        $q = $clientPayload[0];

        $this->assertEquals($pair['question']->id, $q['question_id']);
        $this->assertEquals(1, $q['version']);
        $this->assertEquals('What did the Bereans examine daily?', $q['question_text']);
        $this->assertCount(4, $q['options']);

        // Assert strictly that correct_option_id, explanation, and change_summary are absent
        $this->assertArrayNotHasKey('correct_option_id', $q);
        $this->assertArrayNotHasKey('explanation', $q);
        $this->assertArrayNotHasKey('change_summary', $q);
        $this->assertArrayNotHasKey('created_by_user_id', $q);

        // Assert options contain ONLY 'id' and 'text'
        foreach ($q['options'] as $option) {
            $this->assertArrayHasKey('id', $option);
            $this->assertArrayHasKey('text', $option);
            $this->assertArrayNotHasKey('is_correct', $option);
            $this->assertArrayNotHasKey('weight', $option);
        }
    }

    // =========================================================================
    // 3. DETERMINISTIC SCORING ENGINE (CORRECT, INCORRECT, MIXED)
    // =========================================================================

    public function test_scoring_evaluates_all_correct_answers_accurately(): void
    {
        $pair1 = $this->createQuestionWithRevision(['difficulty' => 'easy'], ['correct_option_id' => 'opt_a']);
        $pair2 = $this->createQuestionWithRevision(['difficulty' => 'medium'], ['correct_option_id' => 'opt_b']);
        $pair3 = $this->createQuestionWithRevision(['difficulty' => 'hard'], ['correct_option_id' => 'opt_c']);

        $manifest = $this->bankService->buildManifest([$pair1['question'], $pair2['question'], $pair3['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a', 'question_version' => 1],
            ['question_id' => $pair2['question']->id, 'selected_option_id' => 'opt_b', 'question_version' => 1],
            ['question_id' => $pair3['question']->id, 'selected_option_id' => 'opt_c', 'question_version' => 1],
        ];

        $result = $this->scoringService->scoreAnswers($manifest, $answers);

        $this->assertEquals(3, $result->totalQuestions);
        $this->assertEquals(3, $result->correctAnswers);
        $this->assertEquals(0, $result->getIncorrectAnswers());
        $this->assertTrue($result->isPerfect());
        $this->assertEquals(100.0, $result->getAccuracyPercentage());

        // Standard policy: easy(10) + medium(15) + hard(20) = 45 XP
        $this->assertEquals(45, $result->grossXp);
    }

    public function test_scoring_evaluates_all_incorrect_answers_accurately(): void
    {
        $pair1 = $this->createQuestionWithRevision(['difficulty' => 'easy'], ['correct_option_id' => 'opt_a']);
        $pair2 = $this->createQuestionWithRevision(['difficulty' => 'medium'], ['correct_option_id' => 'opt_a']);

        $manifest = $this->bankService->buildManifest([$pair1['question'], $pair2['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_b'],
            ['question_id' => $pair2['question']->id, 'selected_option_id' => 'opt_c'],
        ];

        $result = $this->scoringService->scoreAnswers($manifest, $answers);

        $this->assertEquals(2, $result->totalQuestions);
        $this->assertEquals(0, $result->correctAnswers);
        $this->assertEquals(2, $result->getIncorrectAnswers());
        $this->assertFalse($result->isPerfect());
        $this->assertEquals(0.0, $result->getAccuracyPercentage());
        $this->assertEquals(0, $result->grossXp);
    }

    public function test_scoring_evaluates_mixed_answers_accurately(): void
    {
        $pair1 = $this->createQuestionWithRevision(['difficulty' => 'easy'], ['correct_option_id' => 'opt_a']);
        $pair2 = $this->createQuestionWithRevision(['difficulty' => 'hard'], ['correct_option_id' => 'opt_a']);

        $manifest = $this->bankService->buildManifest([$pair1['question'], $pair2['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a'], // Correct: easy = 10 XP
            ['question_id' => $pair2['question']->id, 'selected_option_id' => 'opt_b'], // Incorrect: hard = 0 XP
        ];

        $result = $this->scoringService->scoreAnswers($manifest, $answers);

        $this->assertEquals(2, $result->totalQuestions);
        $this->assertEquals(1, $result->correctAnswers);
        $this->assertEquals(1, $result->getIncorrectAnswers());
        $this->assertEquals(50.0, $result->getAccuracyPercentage());
        $this->assertEquals(10, $result->grossXp);
    }

    // =========================================================================
    // 4. SUBMISSION VALIDATION & TAMPER-RESISTANCE
    // =========================================================================

    public function test_scoring_rejects_duplicate_question_submissions(): void
    {
        $pair1 = $this->createQuestionWithRevision();
        $pair2 = $this->createQuestionWithRevision();

        $manifest = $this->bankService->buildManifest([$pair1['question'], $pair2['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a'],
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a'], // Duplicate!
        ];

        $this->expectException(ScoringValidationException::class);
        $this->expectExceptionMessage("Duplicate answer submitted for question '{$pair1['question']->id}'.");

        try {
            $this->scoringService->scoreAnswers($manifest, $answers);
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_DUPLICATE_QUESTION, $e->errorCode);
            throw $e;
        }
    }

    public function test_scoring_rejects_answer_count_mismatch(): void
    {
        $pair1 = $this->createQuestionWithRevision();
        $pair2 = $this->createQuestionWithRevision();

        $manifest = $this->bankService->buildManifest([$pair1['question'], $pair2['question']]);

        // Submit only 1 answer when 2 were authorized
        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a'],
        ];

        $this->expectException(ScoringValidationException::class);
        $this->expectExceptionMessage('Submitted answer count (1) does not match authorized manifest count (2).');

        try {
            $this->scoringService->scoreAnswers($manifest, $answers);
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_COUNT_MISMATCH, $e->errorCode);
            throw $e;
        }
    }

    public function test_scoring_rejects_unauthorized_question_id(): void
    {
        $pair1 = $this->createQuestionWithRevision();
        $manifest = $this->bankService->buildManifest([$pair1['question']]);

        $unauthorizedId = (string) Str::uuid7();
        $answers = [
            ['question_id' => $unauthorizedId, 'selected_option_id' => 'opt_a'],
        ];

        $this->expectException(ScoringValidationException::class);
        $this->expectExceptionMessage("Submitted question '{$unauthorizedId}' was not part of the authorized quiz manifest.");

        try {
            $this->scoringService->scoreAnswers($manifest, $answers);
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_UNAUTHORIZED_QUESTION, $e->errorCode);
            throw $e;
        }
    }

    public function test_scoring_rejects_revision_version_mismatch(): void
    {
        $pair1 = $this->createQuestionWithRevision(['version' => 2], ['version' => 2]);
        $manifest = $this->bankService->buildManifest([$pair1['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_a', 'question_version' => 1], // Expected 2
        ];

        $this->expectException(ScoringValidationException::class);
        $this->expectExceptionMessage("Version mismatch for question '{$pair1['question']->id}': submitted version 1 does not match authorized version 2.");

        try {
            $this->scoringService->scoreAnswers($manifest, $answers);
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_REVISION_MISMATCH, $e->errorCode);
            throw $e;
        }
    }

    public function test_scoring_rejects_invalid_option_id_not_in_revision(): void
    {
        $pair1 = $this->createQuestionWithRevision();
        $manifest = $this->bankService->buildManifest([$pair1['question']]);

        $answers = [
            ['question_id' => $pair1['question']->id, 'selected_option_id' => 'opt_non_existent'],
        ];

        $this->expectException(ScoringValidationException::class);
        $this->expectExceptionMessage("Option 'opt_non_existent' is not a valid selectable option for question '{$pair1['question']->id}'.");

        try {
            $this->scoringService->scoreAnswers($manifest, $answers);
        } catch (ScoringValidationException $e) {
            $this->assertEquals(ScoringValidationException::CODE_INVALID_OPTION, $e->errorCode);
            throw $e;
        }
    }

    public function test_client_supplied_xp_claims_and_timings_are_strictly_ignored(): void
    {
        $pair = $this->createQuestionWithRevision(['difficulty' => 'easy'], ['correct_option_id' => 'opt_a']);
        $manifest = $this->bankService->buildManifest([$pair['question']]);

        // Malicious client claims 999 XP and 0 time taken
        $answers = [
            [
                'question_id' => $pair['question']->id,
                'selected_option_id' => 'opt_a',
                'claimed_xp' => 999,
                'is_correct' => true,
                'time_taken_ms' => 10,
            ],
        ];

        $result = $this->scoringService->scoreAnswers($manifest, $answers);

        // Server authoritatively calculates easy = 10 XP, completely ignoring claimed_xp = 999
        $this->assertEquals(10, $result->grossXp);
        $this->assertEquals(1, $result->correctAnswers);
    }

    public function test_timing_telemetry_does_not_affect_xp_award(): void
    {
        $pair = $this->createQuestionWithRevision(['difficulty' => 'medium'], ['correct_option_id' => 'opt_a']);
        $manifest = $this->bankService->buildManifest([$pair['question']]);

        // Attempt 1: 100ms
        $resFast = $this->scoringService->scoreAnswers($manifest, [
            ['question_id' => $pair['question']->id, 'selected_option_id' => 'opt_a', 'time_taken_ms' => 100],
        ]);

        // Attempt 2: 600,000ms (10 minutes)
        $resSlow = $this->scoringService->scoreAnswers($manifest, [
            ['question_id' => $pair['question']->id, 'selected_option_id' => 'opt_a', 'time_taken_ms' => 600000],
        ]);

        $this->assertEquals(15, $resFast->grossXp);
        $this->assertEquals(15, $resSlow->grossXp);
        $this->assertEquals($resFast->grossXp, $resSlow->grossXp);
    }

    // =========================================================================
    // 5. ACCOUNTING ISOLATION & ZERO DATABASE MUTATION
    // =========================================================================

    public function test_scoring_engine_executes_purely_in_memory_with_zero_database_mutations(): void
    {
        // Setup initial user, guest, and questions
        $userId = DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'scorer@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => $userId,
            'pilgrim_name' => 'ScoringPilgrim',
            'total_xp' => 100,
        ]);

        $guestId = DB::table('guest_identities')->insertGetId([
            'guest_uuid' => (string) Str::uuid7(),
            'secret_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'completed_quiz_count' => 1,
            'gross_lifetime_xp' => 50,
            'created_at' => now(),
        ]);

        $pair = $this->createQuestionWithRevision(['difficulty' => 'hard'], ['correct_option_id' => 'opt_a']);
        $manifest = $this->bankService->buildManifest([$pair['question']]);

        // Snapshot table counts before scoring
        $userProfilesBefore = DB::table('user_profiles')->get()->toArray();
        $guestsBefore = DB::table('guest_identities')->get()->toArray();
        $ledgerCountBefore = DB::table('user_xp_ledger')->count();
        $dailyCountersCountBefore = DB::table('user_daily_competitive_counters')->count();
        $eventsCountBefore = DB::table('competitive_xp_events')->count();
        $processedCountBefore = DB::table('processed_events')->count();
        $quizSessionsCountBefore = DB::table('quiz_sessions')->count();

        // Execute scoring
        $result = $this->scoringService->scoreAnswers($manifest, [
            ['question_id' => $pair['question']->id, 'selected_option_id' => 'opt_a'],
        ]);

        $this->assertEquals(20, $result->grossXp);

        // Verify zero mutations
        $this->assertEquals($userProfilesBefore, DB::table('user_profiles')->get()->toArray());
        $this->assertEquals($guestsBefore, DB::table('guest_identities')->get()->toArray());
        $this->assertEquals($ledgerCountBefore, DB::table('user_xp_ledger')->count());
        $this->assertEquals($dailyCountersCountBefore, DB::table('user_daily_competitive_counters')->count());
        $this->assertEquals($eventsCountBefore, DB::table('competitive_xp_events')->count());
        $this->assertEquals($processedCountBefore, DB::table('processed_events')->count());
        $this->assertEquals($quizSessionsCountBefore, DB::table('quiz_sessions')->count());
    }
}
