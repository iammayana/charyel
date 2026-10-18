<?php

namespace App\Contracts;

interface QuizScoringPolicyContract
{
    /**
     * Calculate gross XP for a single question response based strictly on difficulty and correctness.
     * Speed / duration timing is explicitly excluded from calculation to eliminate untrusted client manipulation.
     *
     * @param string $difficulty Question difficulty ('easy', 'medium', 'hard')
     * @param bool $isCorrect Whether the submitted answer was correct
     * @return int Gross XP awarded for this question (>= 0)
     */
    public function calculateQuestionXp(string $difficulty, bool $isCorrect): int;

    /**
     * Aggregate total gross XP from the individual evaluated question items.
     *
     * @param array<int, array{difficulty: string, is_correct: bool, xp_awarded: int}> $itemResults
     * @return int Total gross XP for the session
     */
    public function calculateTotalXp(array $itemResults): int;
}
