<?php

namespace App\Services\Quiz\Policies;

use App\Contracts\QuizScoringPolicyContract;

class StandardQuizScoringPolicy implements QuizScoringPolicyContract
{
    public const EASY_CORRECT_XP = 10;
    public const MEDIUM_CORRECT_XP = 15;
    public const HARD_CORRECT_XP = 20;
    public const DEFAULT_CORRECT_XP = 10;
    public const INCORRECT_XP = 0;

    /**
     * Calculate gross XP for a single question response based strictly on difficulty and correctness.
     * Speed / duration timing is explicitly excluded from calculation to eliminate untrusted client manipulation.
     */
    public function calculateQuestionXp(string $difficulty, bool $isCorrect): int
    {
        if (!$isCorrect) {
            return self::INCORRECT_XP;
        }

        return match (strtolower(trim($difficulty))) {
            'easy' => self::EASY_CORRECT_XP,
            'medium' => self::MEDIUM_CORRECT_XP,
            'hard' => self::HARD_CORRECT_XP,
            default => self::DEFAULT_CORRECT_XP,
        };
    }

    /**
     * Aggregate total gross XP from the individual evaluated question items.
     *
     * @param array<int, array{difficulty: string, is_correct: bool, xp_awarded: int}> $itemResults
     */
    public function calculateTotalXp(array $itemResults): int
    {
        $total = 0;
        foreach ($itemResults as $item) {
            $total += max(0, (int) ($item['xp_awarded'] ?? 0));
        }

        return $total;
    }
}
