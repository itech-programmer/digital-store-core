<?php

namespace App\Console\Commands;

use App\Contracts\Catalog\StockCacheServiceInterface;
use Illuminate\Console\Command;

class RebuildStockCacheCommand extends Command
{
    protected $signature = 'catalog:rebuild-stock';

    protected $description = 'Rebuild product_stock_cache from product_keys';

    public function handle(StockCacheServiceInterface $stockCache): int
    {
        $n = $stockCache->rebuildAll();
        $this->info("Stock cache rebuilt for {$n} SKU");

        return self::SUCCESS;
    }
}
