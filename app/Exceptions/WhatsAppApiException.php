<?php

namespace App\Exceptions;

use RuntimeException;

class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?string $providerCode = null,
        private int $statusCode = 502
    ) {
        parent::__construct($message);
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
