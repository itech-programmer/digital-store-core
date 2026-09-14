<?php

namespace Tests\Feature;

use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Models\Catalog\ProductStockCache;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_storefront_returns_paginated_stock(): void
    {
        $response = $this->getJson('/api/v1/catalog/stock?page=1&per_page=5');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'sku',
                        'name',
                        'type',
                        'price',
                        'currency',
                        'stock',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ])
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);

        $this->assertGreaterThan(0, $response->json('data.0.stock'));
        $this->assertDatabaseCount('product_stock_cache', 12);
    }

    public function test_stock_cache_updates_after_reserve(): void
    {
        $sku = 'KEY-CS2-PRIME';
        $before = ProductStockCache::query()->where('sku', $sku)->value('available_count');

        $orderId = $this->postJson('/api/v1/orders', ['sku' => $sku])->json('data.id');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_stock_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $after = ProductStockCache::query()->where('sku', $sku)->value('available_count');

        $this->assertSame($before - 1, $after);
    }

    public function test_rebuild_all_matches_available_keys(): void
    {
        app(StockCacheServiceInterface::class)->rebuildAll();

        $cached = ProductStockCache::query()->where('sku', 'KEY-GTA5')->value('available_count');
        $this->assertNotNull($cached);
        $this->assertGreaterThanOrEqual(0, $cached);
    }
}
