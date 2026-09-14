<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Contracts\Catalog\ProductStockCacheRepositoryInterface;
use App\Contracts\Catalog\StockCacheServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockCacheService implements StockCacheServiceInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductKeyRepositoryInterface $productKeys,
        private readonly ProductStockCacheRepositoryInterface $stockCache,
    ) {}

    public function rebuildAll(): int
    {
        $counts = $this->productKeys->availableCountsBySku();

        $now = now();
        $payload = [];

        foreach ($this->products->pluckAllSkus() as $sku) {
            $payload[] = [
                'sku' => $sku,
                'available_count' => (int) ($counts[$sku] ?? 0),
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            $this->stockCache->upsert($chunk);
        }

        return count($payload);
    }

    public function refreshSku(string $sku): void
    {
        $count = $this->productKeys->countAvailableBySku($sku);

        $this->stockCache->upsert([
            [
                'sku' => $sku,
                'available_count' => $count,
                'updated_at' => now(),
            ],
        ]);
    }

    public function storefront(int $page = 1, int $perPage = 100): LengthAwarePaginator
    {
        return $this->products->storefrontPaginate($page, $perPage);
    }
}
