<?php

namespace App\Contracts\Catalog;

use App\Models\Catalog\Product;

interface ProductRepositoryInterface
{
    public function findActiveBySku(string $sku): ?Product;
}
