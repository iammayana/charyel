<?php

namespace App\Services\Quiz\Dto;

use App\Models\QuestionRevision;

class AuthorizedQuestionItem
{
    public function __construct(
        public readonly string $questionId,
        public readonly int $revisionId,
        public readonly int $version,
        public readonly string $difficulty,
        public readonly ?string $category = null,
        public readonly ?QuestionRevision $revision = null
    ) {}

    /**
     * Convert to an array representation for logging or caching.
     */
    public function toArray(): array
    {
        return [
            'question_id' => $this->questionId,
            'revision_id' => $this->revisionId,
            'version' => $this->version,
            'difficulty' => $this->difficulty,
            'category' => $this->category,
        ];
    }
}
