<?php

namespace Database\Seeders;

use App\Enums\ProductKeyStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductKey;
use App\Services\Catalog\StockCacheService;
use Illuminate\Database\Seeder;

class BulkCatalogSeeder extends Seeder
{
    public int $count = 5000;

    public int $keysPerSku = 2;

    public function run(): void
    {
        $now = now();
        $products = [];
        $keys = [];

        for ($i = 1; $i <= $this->count; $i++) {
            $sku = sprintf('BULK-%05d', $i);
            $products[] = [
                'sku' => $sku,
                'name' => "Bulk digital item {$i}",
                'type' => 'key',
                'price' => 100 + ($i % 50),
                'currency' => 'RUB',
                'image_path' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            for ($k = 1; $k <= $this->keysPerSku; $k++) {
                $hash = md5($sku.'-'.$k);
                $keys[] = [
                    'sku' => $sku,
                    'code' => strtoupper(
                        substr($hash, 0, 4).'-'.substr($hash, 4, 4).'-'.substr($hash, 8, 4)
                    ),
                    'status' => ProductKeyStatus::Available->value,
                    'order_id' => null,
                    'reserved_at' => null,
                    'delivered_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($products) >= 500) {
                Product::query()->upsert($products, ['sku'], ['name', 'type', 'price', 'currency', 'image_path', 'is_active', 'updated_at']);
                $products = [];
            }

            if (count($keys) >= 1000) {
                ProductKey::query()->upsert($keys, ['code'], ['sku', 'status', 'updated_at']);
                $keys = [];
            }
        }

        if ($products !== []) {
            Product::query()->upsert($products, ['sku'], ['name', 'type', 'price', 'currency', 'image_path', 'is_active', 'updated_at']);
        }
        if ($keys !== []) {
            ProductKey::query()->upsert($keys, ['code'], ['sku', 'status', 'updated_at']);
        }

        app(StockCacheService::class)->rebuildAll();
    }
}
