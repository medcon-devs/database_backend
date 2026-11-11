<?php

namespace App\Exceptions;

use RuntimeException;

class MicrosoftAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $errorCode = 'MICROSOFT_AUTH_FAILED',
        private int $statusCode = 401
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
