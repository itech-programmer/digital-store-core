<?php

namespace App\DTO\Supplier;

final readonly class SupplierIssueRequestDto
{
    public function __construct(
        public string $requestId,
        public string $sku,
        public string $orderId,
    ) {}
}
