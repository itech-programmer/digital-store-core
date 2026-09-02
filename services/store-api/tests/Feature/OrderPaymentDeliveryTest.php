<?php

namespace Tests\Feature;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductKey;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderPaymentDeliveryTest extends TestCase
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

    public function test_create_pay_deliver_happy_path(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-CS2-PRIME',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.sku', 'KEY-CS2-PRIME');

        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');

        $webhook = $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_happy_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ]);

        $webhook->assertOk()->assertJsonPath('status', 'accepted');

        $show = $this->getJson('/api/v1/orders/'.$orderId);

        $show->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJson(fn ($json) => $json->whereType('data.issued_code', 'string'));

        $code = $show->json('data.issued_code');
        $this->assertNotEmpty($code);
        $this->assertDatabaseHas('product_keys', [
            'code' => $code,
            'status' => 'delivered',
        ]);
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $payload = [
            'event_id' => 'evt_dup_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ];

        $this->postJson('/api/v1/webhook/payment', $payload)->assertOk();
        $firstCode = $this->getJson('/api/v1/orders/'.$orderId)->json('data.issued_code');

        $this->postJson('/api/v1/webhook/payment', $payload)->assertOk();
        $second = $this->getJson('/api/v1/orders/'.$orderId);

        $second->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.issued_code', $firstCode);

        $this->assertSame(1, \App\Models\Payment\ProcessedWebhookEvent::query()->where('event_id', 'evt_dup_1')->count());
    }

    public function test_webhook_before_order_is_applied_on_create(): void
    {
        $publicId = 'ord_earlypay01';

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_early_1',
            'order_id' => $publicId,
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->assertDatabaseHas('pending_webhooks', [
            'event_id' => 'evt_early_1',
            'order_public_id' => $publicId,
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'sku' => 'KEY-CS2-PRIME',
            'public_id' => $publicId,
        ]);

        $create->assertCreated();

        $this->getJson('/api/v1/orders/'.$publicId)
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseMissing('pending_webhooks', [
            'event_id' => 'evt_early_1',
        ]);
    }

    public function test_unknown_sku_returns_404(): void
    {
        $this->postJson('/api/v1/orders', ['sku' => 'NO-SUCH-SKU'])
            ->assertNotFound();
    }

    public function test_out_of_stock_is_recoverable_status(): void
    {
        ProductKey::query()->where('sku', 'SUB-SPOTIFY-1M')->delete();

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'SUB-SPOTIFY-1M'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_oos_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 299,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'out_of_stock')
            ->assertJsonPath('data.issued_code', null);
    }
}
