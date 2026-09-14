<?php

namespace App\Repositories;

use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Models\Catalog\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function findActiveBySku(string $sku): ?Product
    {
        return Product::query()
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();
    }

    public function pluckAllSkus(): Collection
    {
        return Product::query()->pluck('sku');
    }

    public function storefrontPaginate(int $page, int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->leftJoin('product_stock_cache as s', 's.sku', '=', 'products.sku')
            ->where('products.is_active', true)
            ->orderBy('products.sku')
            ->select([
                'products.sku',
                'products.name',
                'products.type',
                'products.price',
                'products.currency',
                DB::raw('COALESCE(s.available_count, 0) as stock'),
            ])
            ->paginate(perPage: min(max($perPage, 1), 200), page: max($page, 1));
    }
}
