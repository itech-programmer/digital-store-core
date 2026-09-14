<?php

namespace Tests\Feature;

use App\Models\Order\OrderItem;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiItemDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_multi_item_order_delivers_each_line_with_own_supplier(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME', 'qty' => 1],
                ['sku' => 'KEY-GTA5', 'qty' => 1],
                ['sku' => 'SUB-SPOTIFY-1M', 'qty' => 1],
            ],
        ]);

        $create->assertCreated()->assertJsonCount(3, 'data.items');

        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_multi_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $show = $this->getJson('/api/v1/orders/'.$orderId);

        $show->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonCount(3, 'data.items');

        $items = $show->json('data.items');
        $codes = [];
        $suppliers = [];

        foreach ($items as $item) {
            $this->assertSame('delivered', $item['status']);
            $this->assertNotEmpty($item['issued_code']);
            $codes[] = $item['issued_code'];
            $suppliers[] = $item['supplier'];
        }

        $this->assertCount(3, array_unique($codes));
        $this->assertContains('primary', $suppliers);
        $this->assertContains('fallback', $suppliers);

        $this->assertDatabaseCount('order_items', 3);
        $this->assertSame(3, OrderItem::query()->where('status', 'delivered')->count());
    }

    public function test_multi_item_partial_stock_keeps_delivered_and_refunds_failed(): void
    {
        \App\Models\Catalog\ProductKey::query()->where('sku', 'SUB-SPOTIFY-1M')->delete();

        $create = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME', 'qty' => 1],
                ['sku' => 'SUB-SPOTIFY-1M', 'qty' => 1],
            ],
        ]);

        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_multi_partial_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $show = $this->getJson('/api/v1/orders/'.$orderId)->assertOk();

        $this->assertSame('partially_delivered', $show->json('data.status'));

        $items = collect($show->json('data.items'))->keyBy('sku');
        $this->assertSame('delivered', $items['KEY-CS2-PRIME']['status']);
        $this->assertNotEmpty($items['KEY-CS2-PRIME']['issued_code']);
        $this->assertSame('refunded', $items['SUB-SPOTIFY-1M']['status']);
        $this->assertNull($items['SUB-SPOTIFY-1M']['issued_code']);

        $this->assertDatabaseHas('financial_ledger', [
            'event_type' => 'delivery_completed',
            'amount' => 1290,
        ]);
        $this->assertDatabaseHas('financial_ledger', [
            'event_type' => 'refund_issued',
            'amount' => 299,
        ]);
        $this->assertDatabaseHas('financial_ledger', [
            'event_type' => 'payment_received',
            'amount' => 1290 + 299,
        ]);
    }
}
