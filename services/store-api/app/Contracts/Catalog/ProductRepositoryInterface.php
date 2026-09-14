<?php

namespace App\Contracts\Catalog;

use App\Models\Catalog\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    public function findActiveBySku(string $sku): ?Product;

    public function pluckAllSkus(): Collection;

    public function storefrontPaginate(int $page, int $perPage): LengthAwarePaginator;
}
