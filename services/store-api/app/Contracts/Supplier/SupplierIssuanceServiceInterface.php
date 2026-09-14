<?php

namespace App\Contracts\Supplier;

use App\Models\Order\OrderItem;
use App\Models\Supplier\SupplierIssuance;

interface SupplierIssuanceServiceInterface
{
    public function findByRequestId(string $requestId): ?SupplierIssuance;

    public function isAcceptableCode(string $code, ?string $expectedSku = null, ?string $responseSku = null): bool;

    public function registerIssued(
        string $requestId,
        string $supplier,
        OrderItem $item,
        string $code,
        ?array $rawResponse = null,
        ?string $responseSku = null,
    ): array;
}
