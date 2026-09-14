<?php

namespace App\Contracts\Catalog;

interface ProductStockCacheRepositoryInterface
{
    public function upsert(array $rows): void;
}
