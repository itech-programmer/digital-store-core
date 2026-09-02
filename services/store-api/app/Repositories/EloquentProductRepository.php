<?php

namespace App\Repositories;

use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Models\Catalog\Product;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function findActiveBySku(string $sku): ?Product
    {
        return Product::query()
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();
    }
}
