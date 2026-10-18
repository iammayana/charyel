<?php

namespace App\Exceptions;

use RuntimeException;

class ScoringValidationException extends RuntimeException
{
    public const CODE_COUNT_MISMATCH = 'COUNT_MISMATCH';
    public const CODE_DUPLICATE_QUESTION = 'DUPLICATE_QUESTION';
    public const CODE_UNAUTHORIZED_QUESTION = 'UNAUTHORIZED_QUESTION';
    public const CODE_REVISION_MISMATCH = 'REVISION_MISMATCH';
    public const CODE_INVALID_OPTION = 'INVALID_OPTION';
    public const CODE_MALFORMED_SUBMISSION = 'MALFORMED_SUBMISSION';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}
