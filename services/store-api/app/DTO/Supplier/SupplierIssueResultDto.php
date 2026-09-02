<?php

namespace App\DTO\Supplier;

final readonly class SupplierIssueResultDto
{
    public function __construct(
        public bool $success,
        public ?string $code,
        public ?string $reason,
        public bool $timedOut,
    ) {}

    public static function ok(string $code): self
    {
        return new self(true, $code, null, false);
    }

    public static function error(string $reason): self
    {
        return new self(false, null, $reason, false);
    }

    public static function timeout(): self
    {
        return new self(false, null, 'timeout', true);
    }
}
