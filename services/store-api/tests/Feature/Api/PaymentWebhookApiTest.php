<?php

namespace Tests\Feature\Api;

use App\Models\Payment\PendingWebhook;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_webhook_requires_all_fields(): void
    {
        $this->postJson('/api/v1/webhook/payment', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'event_id',
                'order_id',
                'status',
                'amount',
                'currency',
                'created_at',
            ]);
    }

    public function test_webhook_rejects_invalid_status(): void
    {
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_bad_status',
            'order_id' => 'ord_any00000001',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_webhook_for_unknown_order_is_stored_as_pending(): void
    {
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_pending_1',
            'order_id' => 'ord_unknown001',
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('pending_webhooks', [
            'event_id' => 'evt_pending_1',
            'order_public_id' => 'ord_unknown001',
        ]);
    }

    public function test_failed_payment_sets_payment_failed_status(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_failed_1',
            'order_id' => $orderId,
            'status' => 'failed',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_failed')
            ->assertJsonPath('data.issued_code', null);
    }

    public function test_paid_webhook_after_payment_failed_is_ignored(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_fail_first',
            'order_id' => $orderId,
            'status' => 'failed',
            'amount' => 990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_paid_after_fail',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:01:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'payment_failed')
            ->assertJsonPath('data.issued_code', null);
    }

    public function test_pending_webhook_is_upserted_by_event_id(): void
    {
        $payload = [
            'event_id' => 'evt_pending_upsert',
            'order_id' => 'ord_missing0001',
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ];

        $this->postJson('/api/v1/webhook/payment', $payload)->assertOk();
        $this->postJson('/api/v1/webhook/payment', $payload)->assertOk();

        $this->assertSame(1, PendingWebhook::query()->where('event_id', 'evt_pending_upsert')->count());
    }
}
