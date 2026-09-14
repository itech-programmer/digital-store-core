<?php

namespace App\Services\Order;

use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Contracts\Order\ProductKeyInventoryInterface;
use App\Enums\ProductKeyStatus;
use App\Models\Catalog\ProductKey;

class ProductKeyInventory implements ProductKeyInventoryInterface
{
    public function __construct(
        private readonly ProductKeyRepositoryInterface $productKeys,
        private readonly StockCacheServiceInterface $stockCache,
    ) {}

    public function reserve(string $sku, string $orderId, ?string $orderItemId = null): ?ProductKey
    {
        $key = $this->productKeys->lockFirstAvailableBySku($sku);

        if ($key === null) {
            return null;
        }

        $key->status = ProductKeyStatus::Reserved;
        $key->order_id = $orderId;
        $key->order_item_id = $orderItemId;
        $key->reserved_at = now();
        $this->productKeys->save($key);

        $this->stockCache->refreshSku($sku);

        return $key;
    }

    public function markDelivered(ProductKey $key): void
    {
        $key->status = ProductKeyStatus::Delivered;
        $key->delivered_at = now();
        $this->productKeys->save($key);

        $this->stockCache->refreshSku($key->sku);
    }

    public function release(ProductKey $key): void
    {
        $key->status = ProductKeyStatus::Available;
        $key->order_id = null;
        $key->order_item_id = null;
        $key->reserved_at = null;
        $this->productKeys->save($key);

        $this->stockCache->refreshSku($key->sku);
    }
}
