<?php

namespace App\Repositories;

use App\Contracts\Supplier\SupplierIssuanceRepositoryInterface;
use App\Models\Supplier\SupplierIssuance;

class EloquentSupplierIssuanceRepository implements SupplierIssuanceRepositoryInterface
{
    public function findByRequestId(string $requestId): ?SupplierIssuance
    {
        return SupplierIssuance::query()->where('request_id', $requestId)->first();
    }

    public function create(array $attributes): SupplierIssuance
    {
        return SupplierIssuance::query()->create($attributes);
    }

    public function findIssuedByCode(string $code): ?SupplierIssuance
    {
        return SupplierIssuance::query()
            ->where('code', $code)
            ->where('status', 'issued')
            ->first();
    }

    public function hasIssuedWithCodeForItem(string $orderItemId): bool
    {
        return SupplierIssuance::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', 'issued')
            ->whereNotNull('code')
            ->exists();
    }

    public function hasIssuedByRequestId(string $requestId): bool
    {
        return SupplierIssuance::query()
            ->where('request_id', $requestId)
            ->where('status', 'issued')
            ->whereNotNull('code')
            ->exists();
    }
}
