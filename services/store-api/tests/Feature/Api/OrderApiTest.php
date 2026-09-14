<?php

namespace Tests\Feature\Api;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_create_order_with_custom_public_id(): void
    {
        $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-CS2-PRIME',
            'public_id' => 'ord_custom0001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', 'ord_custom0001')
            ->assertJsonPath('data.status', 'created');
    }

    public function test_create_order_requires_sku_or_items(): void
    {
        $this->postJson('/api/v1/orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_create_multi_item_order(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME', 'qty' => 1],
                ['sku' => 'KEY-GTA5', 'qty' => 1],
                ['sku' => 'SUB-SPOTIFY-1M', 'qty' => 1],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.amount', 1290 + 1990 + 299)
            ->assertJsonCount(3, 'data.items');

        $items = $response->json('data.items');
        $skus = array_column($items, 'sku');
        sort($skus);
        $this->assertSame(['KEY-CS2-PRIME', 'KEY-GTA5', 'SUB-SPOTIFY-1M'], $skus);

        foreach ($items as $item) {
            $this->assertSame('pending', $item['status']);
            $this->assertContains($item['supplier'], ['primary', 'fallback']);
            $this->assertNull($item['issued_code']);
        }

        $orderId = $response->json('data.id');
        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.amount', 1290 + 1990 + 299);
    }

    public function test_create_order_expands_qty_into_lines(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME', 'qty' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.amount', 1290 * 2);
    }

    public function test_legacy_sku_creates_one_item(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-GTA5',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.sku', 'KEY-GTA5')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.sku', 'KEY-GTA5')
            ->assertJsonPath('data.items.0.status', 'pending');
    }

    public function test_create_order_rejects_invalid_public_id_format(): void
    {
        $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-CS2-PRIME',
            'public_id' => 'INVALID-ID',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['public_id']);
    }

    public function test_create_order_rejects_duplicate_public_id(): void
    {
        $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-CS2-PRIME',
            'public_id' => 'ord_dup0000001',
        ])->assertCreated();

        $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-GTA5',
            'public_id' => 'ord_dup0000001',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['public_id']);
    }

    public function test_show_order_not_found(): void
    {
        $this->getJson('/api/v1/orders/ord_notexists0')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found');
    }

    public function test_show_order_returns_full_payload(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])
            ->json('data.id');

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'sku',
                    'amount',
                    'currency',
                    'status',
                    'issued_code',
                    'created_at',
                    'paid_at',
                    'delivered_at',
                ],
            ])
            ->assertJsonPath('data.sku', 'KEY-GTA5')
            ->assertJsonPath('data.status', 'created');
    }
}
