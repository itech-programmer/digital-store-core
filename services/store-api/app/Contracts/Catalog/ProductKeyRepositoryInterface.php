<?php

namespace App\Contracts\Catalog;

use App\Models\Catalog\ProductKey;
use Illuminate\Support\Collection;

interface ProductKeyRepositoryInterface
{
    public function save(ProductKey $key): void;

    public function findByIdForUpdate(string $id): ?ProductKey;

    public function lockFirstAvailableBySku(string $sku): ?ProductKey;

    public function listReservedByOrderItemIdForUpdate(string $orderItemId): Collection;

    public function availableCountsBySku(): Collection;

    public function countAvailableBySku(string $sku): int;
}
