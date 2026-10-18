<?php

namespace App\Services\Quiz;

use App\Models\Question;
use App\Services\Quiz\Dto\AuthorizedQuestionItem;
use App\Services\Quiz\Dto\AuthorizedQuestionManifest;
use Illuminate\Support\Str;
use RuntimeException;

class QuestionBankService
{
    /**
     * Select a random set of published questions matching criteria and package them into an immutable manifest.
     *
     * @param int $count Number of questions to select (default 10)
     * @param array{
     *     category?: string|null,
     *     difficulty?: string|null,
     *     testament?: string|null,
     *     quiz_mode?: string
     * } $filters
     * @throws RuntimeException If insufficient published questions exist
     */
    public function selectQuestions(int $count = 10, array $filters = []): AuthorizedQuestionManifest
    {
        if ($count <= 0) {
            throw new RuntimeException("Question count must be strictly positive.");
        }

        $category = $filters['category'] ?? null;
        $difficulty = $filters['difficulty'] ?? null;
        $testament = $filters['testament'] ?? null;
        $quizMode = $filters['quiz_mode'] ?? 'standard';

        $query = Question::query()
            ->published()
            ->category($category)
            ->difficulty($difficulty)
            ->testament($testament)
            ->with('currentRevision');

        $questions = $query->inRandomOrder()->limit($count)->get();

        if ($questions->count() < $count) {
            throw new RuntimeException(
                "Insufficient published questions available (found {$questions->count()}, requested {$count})."
            );
        }

        return $this->buildManifest($questions, $quizMode, $difficulty ?? 'mixed', $category);
    }

    /**
     * Construct an immutable AuthorizedQuestionManifest from a given sequence of Question models.
     *
     * @param iterable<Question> $questions
     * @throws RuntimeException If any question lacks a valid current revision or options
     */
    public function buildManifest(
        iterable $questions,
        string $quizMode = 'standard',
        string $difficulty = 'mixed',
        ?string $category = null
    ): AuthorizedQuestionManifest {
        $items = [];

        foreach ($questions as $question) {
            $revision = $question->currentRevision;

            if ($revision === null) {
                throw new RuntimeException("Question {$question->id} has no valid current revision assigned.");
            }

            if (!is_array($revision->options) || count($revision->options) < 2) {
                throw new RuntimeException("Question {$question->id} revision {$revision->id} has invalid or insufficient options.");
            }

            if (empty($revision->correct_option_id)) {
                throw new RuntimeException("Question {$question->id} revision {$revision->id} has no correct_option_id defined.");
            }

            $items[] = new AuthorizedQuestionItem(
                questionId: (string) $question->id,
                revisionId: (int) $revision->id,
                version: (int) $revision->version,
                difficulty: (string) $question->difficulty,
                category: $question->category,
                revision: $revision
            );
        }

        $manifestId = (string) Str::uuid7();

        return new AuthorizedQuestionManifest(
            manifestId: $manifestId,
            quizMode: $quizMode,
            difficulty: $difficulty,
            category: $category,
            items: $items
        );
    }
}
