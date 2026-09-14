<?php

namespace App\Contracts\Order;

use App\Models\Catalog\ProductKey;

interface ProductKeyInventoryInterface
{
    public function reserve(string $sku, string $orderId, ?string $orderItemId = null): ?ProductKey;

    public function markDelivered(ProductKey $key): void;

    public function release(ProductKey $key): void;
}
