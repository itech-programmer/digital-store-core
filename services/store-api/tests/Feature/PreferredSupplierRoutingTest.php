<?php

namespace Tests\Feature;

use App\Models\Catalog\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferredSupplierRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_line_uses_product_preferred_supplier(): void
    {
        $this->assertSame('fallback', Product::query()->where('sku', 'KEY-CS2-PRIME')->value('preferred_supplier'));
        $this->assertSame('primary', Product::query()->where('sku', 'KEY-GTA5')->value('preferred_supplier'));

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME'],
                ['sku' => 'KEY-GTA5'],
            ],
        ])->json('data.id');

        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_pref_route',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2026-09-14T12:00:00Z',
        ])->assertOk();

        $items = collect($this->getJson('/api/v1/orders/'.$orderId)->json('data.items'))->keyBy('sku');

        $this->assertSame('fallback', $items['KEY-CS2-PRIME']['supplier']);
        $this->assertSame('primary', $items['KEY-GTA5']['supplier']);
    }
}
