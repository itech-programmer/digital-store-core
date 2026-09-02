<?php

namespace App\Console\Commands;

use App\Contracts\Catalog\StockCacheServiceInterface;
use Database\Seeders\BulkCatalogSeeder;
use Illuminate\Console\Command;

class SeedBulkCatalogCommand extends Command
{
    protected $signature = 'catalog:seed-bulk {--count=5000} {--keys=2}';

    protected $description = 'Seed thousands of SKU for storefront load tests and rebuild stock cache';

    public function handle(StockCacheServiceInterface $stockCache): int
    {
        $seeder = new BulkCatalogSeeder;
        $seeder->count = max(1, (int) $this->option('count'));
        $seeder->keysPerSku = max(1, (int) $this->option('keys'));
        $seeder->setContainer($this->laravel);
        $seeder->run();

        $n = $stockCache->rebuildAll();
        $this->info("Bulk catalog seeded ({$seeder->count} SKU). Stock cache rows: {$n}");

        return self::SUCCESS;
    }
}
