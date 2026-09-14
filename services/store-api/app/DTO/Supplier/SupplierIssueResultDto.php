<?php

namespace App\DTO\Supplier;

final readonly class SupplierIssueResultDto
{
    public function __construct(
        public bool $success,
        public ?string $code,
        public ?string $reason,
        public bool $timedOut,
        public bool $rateLimited = false,
        public ?int $retryAfterSeconds = null,
        public ?string $responseSku = null,
    ) {}

    public static function ok(string $code, ?string $responseSku = null): self
    {
        return new self(true, $code, null, false, false, null, $responseSku);
    }

    public static function error(string $reason): self
    {
        return new self(false, null, $reason, false);
    }

    public static function timeout(): self
    {
        return new self(false, null, 'timeout', true);
    }

    public static function rateLimited(int $retryAfterSeconds = 60): self
    {
        return new self(false, null, 'rate_limited', false, true, max(1, $retryAfterSeconds));
    }
}
