<?php

namespace Tests\Feature;

use App\Models\Catalog\ProductKey;
use App\Models\Order\Order;
use App\Models\Payment\ProcessedWebhookEvent;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_fifty_distinct_event_ids_produce_exactly_one_delivery(): void
    {
        $create = $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME']);
        $create->assertCreated();

        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');
        $internalId = Order::query()->where('public_id', $orderId)->value('id');

        for ($i = 0; $i < 50; $i++) {
            $this->postJson('/api/v1/webhook/payment', [
                'event_id' => "evt_multi_{$i}",
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => $amount,
                'currency' => 'RUB',
                'created_at' => '2025-01-01T12:00:00Z',
            ])->assertOk();
        }

        $show = $this->getJson('/api/v1/orders/'.$orderId);
        $show->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $code = $show->json('data.issued_code');
        $this->assertNotEmpty($code);

        $this->assertSame(
            50,
            ProcessedWebhookEvent::query()->where('order_id', $internalId)->count(),
            'all 50 event_ids must be stored'
        );

        $this->assertSame(
            1,
            ProductKey::query()
                ->where('order_id', $internalId)
                ->where('status', 'delivered')
                ->count(),
            'exactly one key must be delivered'
        );

        $this->assertSame(
            1,
            Order::query()->where('public_id', $orderId)->whereNotNull('issued_code')->count()
        );
    }

    public function test_fifty_duplicate_same_event_id_produce_exactly_one_delivery(): void
    {
        $create = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5']);
        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');
        $internalId = Order::query()->where('public_id', $orderId)->value('id');

        for ($i = 0; $i < 50; $i++) {
            $this->postJson('/api/v1/webhook/payment', [
                'event_id' => 'evt_same_always',
                'order_id' => $orderId,
                'status' => 'paid',
                'amount' => $amount,
                'currency' => 'RUB',
                'created_at' => '2025-01-01T12:00:00Z',
            ])->assertOk();
        }

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_same_always')->count());
        $this->assertSame(
            1,
            ProductKey::query()->where('order_id', $internalId)->where('status', 'delivered')->count()
        );
    }
}
