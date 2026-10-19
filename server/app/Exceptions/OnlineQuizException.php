<?php

namespace App\Exceptions;

use RuntimeException;

class OnlineQuizException extends RuntimeException
{
    public const CODE_ACTIVE_SESSION_EXISTS = 'ACTIVE_SESSION_EXISTS';
    public const CODE_TRIAL_QUIZ_LIMIT_REACHED = 'TRIAL_QUIZ_LIMIT_REACHED';
    public const CODE_TRIAL_XP_LIMIT_REACHED = 'TRIAL_XP_LIMIT_REACHED';
    public const CODE_TRIAL_CAPACITY_EXHAUSTED = 'TRIAL_CAPACITY_EXHAUSTED';
    public const CODE_INSUFFICIENT_QUESTIONS = 'INSUFFICIENT_QUESTIONS';
    public const CODE_SESSION_NOT_FOUND = 'SESSION_NOT_FOUND';
    public const CODE_SESSION_FORBIDDEN = 'SESSION_FORBIDDEN';
    public const CODE_SESSION_EXPIRED = 'SESSION_EXPIRED';
    public const CODE_SESSION_ABANDONED = 'SESSION_ABANDONED';
    public const CODE_SESSION_ALREADY_COMPLETED = 'SESSION_ALREADY_COMPLETED';
    public const CODE_IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    public const CODE_CONTACT_REQUIRED = 'CONTACT_REQUIRED';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 422,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}
