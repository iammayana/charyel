<?php

namespace App\Exceptions;

use RuntimeException;

class ProtectedDatabaseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $command = '',
        public readonly string $database = '',
        public readonly string $connection = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
