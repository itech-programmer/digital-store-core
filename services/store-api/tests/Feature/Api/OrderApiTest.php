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

        Http::fake([
            '*/issue' => Http::response([
                'status' => 'ok',
                'request_id' => 'ignored',
                'code' => 'SUPPLIER-CODE',
            ], 200),
        ]);
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

    public function test_create_order_requires_sku(): void
    {
        $this->postJson('/api/v1/orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
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
