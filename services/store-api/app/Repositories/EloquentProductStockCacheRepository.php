<?php

namespace App\Repositories;

use App\Contracts\Catalog\ProductStockCacheRepositoryInterface;
use App\Models\Catalog\ProductStockCache;

class EloquentProductStockCacheRepository implements ProductStockCacheRepositoryInterface
{
    public function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        ProductStockCache::query()->upsert($rows, ['sku'], ['available_count', 'updated_at']);
    }
}
