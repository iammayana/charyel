<?php

namespace App\Services\Quiz\Dto;

class ScoringResult
{
    /**
     * @param int $totalQuestions
     * @param int $correctAnswers
     * @param int $grossXp
     * @param array<int, QuestionScoreItem> $itemResults
     */
    public function __construct(
        public readonly int $totalQuestions,
        public readonly int $correctAnswers,
        public readonly int $grossXp,
        public readonly array $itemResults
    ) {}

    /**
     * Count of incorrect answers.
     */
    public function getIncorrectAnswers(): int
    {
        return max(0, $this->totalQuestions - $this->correctAnswers);
    }

    /**
     * Whether all answers were correct.
     */
    public function isPerfect(): bool
    {
        return $this->totalQuestions > 0 && $this->correctAnswers === $this->totalQuestions;
    }

    /**
     * Accuracy percentage (0.0 to 100.0).
     */
    public function getAccuracyPercentage(): float
    {
        if ($this->totalQuestions === 0) {
            return 0.0;
        }

        return round(($this->correctAnswers / $this->totalQuestions) * 100.0, 2);
    }

    /**
     * Export as structured array for session record creation.
     */
    public function toArray(): array
    {
        return [
            'total_questions' => $this->totalQuestions,
            'correct_answers' => $this->correctAnswers,
            'incorrect_answers' => $this->getIncorrectAnswers(),
            'gross_xp' => $this->grossXp,
            'is_perfect' => $this->isPerfect(),
            'accuracy_percentage' => $this->getAccuracyPercentage(),
            'item_results' => array_map(fn(QuestionScoreItem $item) => $item->toArray(), $this->itemResults),
        ];
    }
}
