<?php

namespace App\Exceptions;

use RuntimeException;

class CsvImportException extends RuntimeException
{
    public function __construct(
        string $message,
        private array $errors = [],
        private int $statusCode = 422,
        private int $totalErrors = 0
    ) {
        parent::__construct($message);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function totalErrors(): int
    {
        return $this->totalErrors ?: count($this->errors);
    }
}
