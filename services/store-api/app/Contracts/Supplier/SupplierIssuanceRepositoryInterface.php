<?php

namespace App\Contracts\Supplier;

use App\Models\Supplier\SupplierIssuance;

interface SupplierIssuanceRepositoryInterface
{
    public function findByRequestId(string $requestId): ?SupplierIssuance;

    public function create(array $attributes): SupplierIssuance;

    public function findIssuedByCode(string $code): ?SupplierIssuance;

    public function hasIssuedWithCodeForItem(string $orderItemId): bool;

    public function hasIssuedByRequestId(string $requestId): bool;
}
