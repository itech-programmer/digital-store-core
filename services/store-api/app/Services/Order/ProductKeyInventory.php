<?php

namespace App\Services\Order;

use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Enums\ProductKeyStatus;
use App\Models\Catalog\ProductKey;

class ProductKeyInventory
{
    public function __construct(
        private readonly StockCacheServiceInterface $stockCache,
    ) {}

    public function reserve(string $sku, string $orderId): ?ProductKey
    {
        $key = ProductKey::query()
            ->where('sku', $sku)
            ->where('status', ProductKeyStatus::Available)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($key === null) {
            return null;
        }

        $key->status = ProductKeyStatus::Reserved;
        $key->order_id = $orderId;
        $key->reserved_at = now();
        $key->save();

        $this->stockCache->refreshSku($sku);

        return $key;
    }

    public function markDelivered(ProductKey $key): void
    {
        $key->status = ProductKeyStatus::Delivered;
        $key->delivered_at = now();
        $key->save();

        $this->stockCache->refreshSku($key->sku);
    }

    public function release(ProductKey $key): void
    {
        $key->status = ProductKeyStatus::Available;
        $key->order_id = null;
        $key->reserved_at = null;
        $key->save();

        $this->stockCache->refreshSku($key->sku);
    }
}
