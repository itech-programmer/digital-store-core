<?php

namespace App\Exceptions;

use RuntimeException;

class SupplierRateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds = 60,
        string $message = 'Supplier rate limited',
    ) {
        parent::__construct($message);
    }
}
