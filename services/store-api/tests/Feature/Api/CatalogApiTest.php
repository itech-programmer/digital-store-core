<?php

namespace Tests\Feature\Api;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_stock_uses_default_pagination(): void
    {
        $response = $this->getJson('/api/v1/catalog/stock');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(12, 'data');
    }

    public function test_stock_second_page(): void
    {
        $response = $this->getJson('/api/v1/catalog/stock?page=2&per_page=5');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(5, 'data');
    }

    public function test_stock_item_has_expected_fields(): void
    {
        $item = $this->getJson('/api/v1/catalog/stock?per_page=1')
            ->json('data.0');

        $this->assertArrayHasKey('sku', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('price', $item);
        $this->assertArrayHasKey('currency', $item);
        $this->assertArrayHasKey('stock', $item);
        $this->assertIsInt($item['stock']);
    }
}
