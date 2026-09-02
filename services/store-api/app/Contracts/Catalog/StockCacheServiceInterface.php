<?php

namespace App\Contracts\Catalog;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StockCacheServiceInterface
{
    public function rebuildAll(): int;

    public function refreshSku(string $sku): void;

    public function storefront(int $page = 1, int $perPage = 100): LengthAwarePaginator;
}
