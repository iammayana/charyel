<?php

namespace App\Services\Quiz;

use App\Contracts\QuizScoringPolicyContract;
use App\Exceptions\ScoringValidationException;
use App\Models\QuestionRevision;
use App\Services\Quiz\Dto\AuthorizedQuestionManifest;
use App\Services\Quiz\Dto\QuestionScoreItem;
use App\Services\Quiz\Dto\ScoringResult;

class AuthoritativeScoringService
{
    public function __construct(
        protected QuizScoringPolicyContract $policy
    ) {}

    /**
     * Authoritatively scores a batch of submitted question answers against an immutable AuthorizedQuestionManifest.
     *
     * @param AuthorizedQuestionManifest $manifest The server-authorized question manifest
     * @param array<int, array{
     *     question_id: string,
     *     selected_option_id: string,
     *     question_version?: int,
     *     revision_id?: int,
     *     time_taken_ms?: int
     * }> $submittedAnswers
     * @return ScoringResult Deterministic, verified scoring breakdown
     * @throws ScoringValidationException If answers are incomplete, duplicated, unauthorized, or malformed
     */
    public function scoreAnswers(AuthorizedQuestionManifest $manifest, array $submittedAnswers): ScoringResult
    {
        // 1. Verify exact answer count
        $manifestCount = $manifest->count();
        $submittedCount = count($submittedAnswers);

        if ($submittedCount !== $manifestCount) {
            throw new ScoringValidationException(
                "Submitted answer count ({$submittedCount}) does not match authorized manifest count ({$manifestCount}).",
                ScoringValidationException::CODE_COUNT_MISMATCH,
                ['expected' => $manifestCount, 'submitted' => $submittedCount]
            );
        }

        // 2. Track seen questions to prevent duplicate answer injection
        $seenQuestionIds = [];
        $itemResults = [];
        $correctCount = 0;

        foreach ($submittedAnswers as $index => $answer) {
            if (!is_array($answer)) {
                throw new ScoringValidationException(
                    "Submitted answer at index {$index} is not an array.",
                    ScoringValidationException::CODE_MALFORMED_SUBMISSION
                );
            }

            if (!isset($answer['question_id']) || !is_string($answer['question_id'])) {
                throw new ScoringValidationException(
                    "Submitted answer at index {$index} is missing a valid 'question_id'.",
                    ScoringValidationException::CODE_MALFORMED_SUBMISSION
                );
            }

            $questionId = trim($answer['question_id']);

            if (!isset($answer['selected_option_id']) || !is_string($answer['selected_option_id'])) {
                throw new ScoringValidationException(
                    "Submitted answer for question '{$questionId}' is missing a valid 'selected_option_id'.",
                    ScoringValidationException::CODE_MALFORMED_SUBMISSION
                );
            }

            $selectedOptionId = trim($answer['selected_option_id']);
            $timeTakenMs = max(0, (int) ($answer['time_taken_ms'] ?? 0));

            // Prevent duplicate answer submissions for the same question
            if (isset($seenQuestionIds[$questionId])) {
                throw new ScoringValidationException(
                    "Duplicate answer submitted for question '{$questionId}'.",
                    ScoringValidationException::CODE_DUPLICATE_QUESTION,
                    ['question_id' => $questionId]
                );
            }
            $seenQuestionIds[$questionId] = true;

            // 3. Ensure question was part of the authorized manifest
            $manifestItem = $manifest->getItem($questionId);
            if ($manifestItem === null) {
                throw new ScoringValidationException(
                    "Submitted question '{$questionId}' was not part of the authorized quiz manifest.",
                    ScoringValidationException::CODE_UNAUTHORIZED_QUESTION,
                    ['question_id' => $questionId]
                );
            }

            // 4. Verify immutable revision version matching if supplied by client
            if (isset($answer['question_version']) && (int) $answer['question_version'] !== $manifestItem->version) {
                throw new ScoringValidationException(
                    "Version mismatch for question '{$questionId}': submitted version {$answer['question_version']} does not match authorized version {$manifestItem->version}.",
                    ScoringValidationException::CODE_REVISION_MISMATCH,
                    ['question_id' => $questionId, 'expected_version' => $manifestItem->version, 'submitted_version' => $answer['question_version']]
                );
            }

            if (isset($answer['revision_id']) && (int) $answer['revision_id'] !== $manifestItem->revisionId) {
                throw new ScoringValidationException(
                    "Revision ID mismatch for question '{$questionId}': submitted revision {$answer['revision_id']} does not match authorized revision {$manifestItem->revisionId}.",
                    ScoringValidationException::CODE_REVISION_MISMATCH,
                    ['question_id' => $questionId, 'expected_revision_id' => $manifestItem->revisionId, 'submitted_revision_id' => $answer['revision_id']]
                );
            }

            // 5. Retrieve authoritative revision record
            $revision = $manifestItem->revision ?? QuestionRevision::find($manifestItem->revisionId);

            if ($revision === null) {
                throw new ScoringValidationException(
                    "Authoritative revision record {$manifestItem->revisionId} could not be resolved for question '{$questionId}'.",
                    ScoringValidationException::CODE_REVISION_MISMATCH
                );
            }

            // 6. Validate that selected_option_id exists in the revision's options list
            if (!$revision->hasOption($selectedOptionId)) {
                throw new ScoringValidationException(
                    "Option '{$selectedOptionId}' is not a valid selectable option for question '{$questionId}'.",
                    ScoringValidationException::CODE_INVALID_OPTION,
                    ['question_id' => $questionId, 'invalid_option_id' => $selectedOptionId]
                );
            }

            // 7. Authoritatively determine correctness and award XP
            $isCorrect = $revision->isCorrect($selectedOptionId);
            if ($isCorrect) {
                $correctCount++;
            }

            $itemXp = $this->policy->calculateQuestionXp($manifestItem->difficulty, $isCorrect);

            $itemResults[] = new QuestionScoreItem(
                questionId: $manifestItem->questionId,
                revisionId: $manifestItem->revisionId,
                version: $manifestItem->version,
                difficulty: $manifestItem->difficulty,
                selectedOptionId: $selectedOptionId,
                isCorrect: $isCorrect,
                xpAwarded: $itemXp,
                timeTakenMs: $timeTakenMs
            );
        }

        // 8. Calculate total gross XP
        $itemsForTotal = array_map(fn(QuestionScoreItem $item) => [
            'difficulty' => $item->difficulty,
            'is_correct' => $item->isCorrect,
            'xp_awarded' => $item->xpAwarded,
        ], $itemResults);

        $totalGrossXp = $this->policy->calculateTotalXp($itemsForTotal);

        return new ScoringResult(
            totalQuestions: $manifestCount,
            correctAnswers: $correctCount,
            grossXp: $totalGrossXp,
            itemResults: $itemResults
        );
    }
}
