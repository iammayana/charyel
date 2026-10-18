<?php

namespace App\Services\Quiz\Dto;

class AuthorizedQuestionManifest
{
    /**
     * @param string $manifestId Unique UUID for this question manifest
     * @param string $quizMode e.g. 'standard', 'blitz'
     * @param string $difficulty e.g. 'mixed', 'medium'
     * @param string|null $category e.g. 'Acts & Early Church'
     * @param array<int, AuthorizedQuestionItem> $items Ordered list of authorized questions
     */
    public function __construct(
        public readonly string $manifestId,
        public readonly string $quizMode,
        public readonly string $difficulty,
        public readonly ?string $category,
        public readonly array $items
    ) {}

    /**
     * Return total number of authorized questions.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Return ordered list of authorized question IDs.
     *
     * @return array<int, string>
     */
    public function getOrderedQuestionIds(): array
    {
        return array_map(fn(AuthorizedQuestionItem $item) => $item->questionId, $this->items);
    }

    /**
     * Check if a question ID is part of this authorized manifest.
     */
    public function hasQuestion(string $questionId): bool
    {
        return $this->getItem($questionId) !== null;
    }

    /**
     * Retrieve an authorized item by question ID.
     */
    public function getItem(string $questionId): ?AuthorizedQuestionItem
    {
        foreach ($this->items as $item) {
            if ($item->questionId === $questionId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Generates the client-safe array of questions.
     * Guaranteed to NEVER leak correct_option_id, explanation, or internal scoring metadata.
     *
     * @return array<int, array{
     *     question_id: string,
     *     version: int,
     *     question_text: string,
     *     options: array<int, array{id: string, text: string}>,
     *     category: string|null,
     *     difficulty: string|null,
     *     scripture_reference: string|null,
     *     scripture_version: string
     * }>
     */
    public function toClientSafeArray(): array
    {
        $safeQuestions = [];
        foreach ($this->items as $item) {
            if ($item->revision !== null) {
                $safeQuestions[] = $item->revision->toClientSafeArray();
            } else {
                $safeQuestions[] = [
                    'question_id' => $item->questionId,
                    'version' => $item->version,
                    'category' => $item->category,
                    'difficulty' => $item->difficulty,
                ];
            }
        }

        return $safeQuestions;
    }
}
