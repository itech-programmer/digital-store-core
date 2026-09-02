<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Enums\ProductKeyStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductKey;
use App\Models\Catalog\ProductStockCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockCacheService implements StockCacheServiceInterface
{
    public function rebuildAll(): int
    {
        $counts = ProductKey::query()
            ->select([
                'sku',
                DB::raw("SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_count"),
            ])
            ->groupBy('sku')
            ->pluck('available_count', 'sku');

        $now = now();
        $payload = [];

        foreach (Product::query()->pluck('sku') as $sku) {
            $payload[] = [
                'sku' => $sku,
                'available_count' => (int) ($counts[$sku] ?? 0),
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            ProductStockCache::query()->upsert($chunk, ['sku'], ['available_count', 'updated_at']);
        }

        return count($payload);
    }

    public function refreshSku(string $sku): void
    {
        $count = ProductKey::query()
            ->where('sku', $sku)
            ->where('status', ProductKeyStatus::Available)
            ->count();

        ProductStockCache::query()->upsert([
            [
                'sku' => $sku,
                'available_count' => $count,
                'updated_at' => now(),
            ],
        ], ['sku'], ['available_count', 'updated_at']);
    }

    public function storefront(int $page = 1, int $perPage = 100): LengthAwarePaginator
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
