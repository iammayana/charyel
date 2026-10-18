<?php

namespace App\Services\Quiz\Dto;

class QuestionScoreItem
{
    public function __construct(
        public readonly string $questionId,
        public readonly int $revisionId,
        public readonly int $version,
        public readonly string $difficulty,
        public readonly string $selectedOptionId,
        public readonly bool $isCorrect,
        public readonly int $xpAwarded,
        public readonly int $timeTakenMs = 0
    ) {}

    /**
     * Convert to an array representation for session item persistence or logging.
     */
    public function toArray(): array
    {
        return [
            'question_id' => $this->questionId,
            'revision_id' => $this->revisionId,
            'version' => $this->version,
            'difficulty' => $this->difficulty,
            'selected_option_id' => $this->selectedOptionId,
            'is_correct' => $this->isCorrect,
            'xp_awarded' => $this->xpAwarded,
            'time_taken_ms' => $this->timeTakenMs,
        ];
    }
}
