<?php

namespace App\Repositories;

use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Enums\ProductKeyStatus;
use App\Models\Catalog\ProductKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentProductKeyRepository implements ProductKeyRepositoryInterface
{
    public function save(ProductKey $key): void
    {
        $key->save();
    }

    public function findByIdForUpdate(string $id): ?ProductKey
    {
        return ProductKey::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    public function lockFirstAvailableBySku(string $sku): ?ProductKey
    {
        return ProductKey::query()
            ->where('sku', $sku)
            ->where('status', ProductKeyStatus::Available)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    public function listReservedByOrderItemIdForUpdate(string $orderItemId): Collection
    {
        return ProductKey::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', ProductKeyStatus::Reserved->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function availableCountsBySku(): Collection
    {
        return ProductKey::query()
            ->select([
                'sku',
                DB::raw("SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_count"),
            ])
            ->groupBy('sku')
            ->pluck('available_count', 'sku');
    }

    public function countAvailableBySku(string $sku): int
    {
        return ProductKey::query()
            ->where('sku', $sku)
            ->where('status', ProductKeyStatus::Available)
            ->count();
    }
}
