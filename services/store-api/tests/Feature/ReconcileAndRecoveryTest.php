<?php

namespace Tests\Feature;

use App\Enums\ProductKeyStatus;
use App\Models\Catalog\ProductKey;
use App\Models\Payment\FinancialLedgerEntry;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_reconcile_requires_admin_token(): void
    {
        $this->getJson('/api/v1/admin/reconcile')->assertUnauthorized();
    }

    public function test_happy_path_reconcile_is_clean_and_ledger_balanced(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])->json('data.id');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_rec_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'delivered');

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertOk()
            ->assertJsonPath('paid_not_delivered', [])
            ->assertJsonPath('delivered_not_paid', [])
            ->assertJsonPath('unbalanced_orders', [])
            ->assertJsonPath('ledger_balanced', true)
            ->assertJsonPath('ledger_refund_sum', 0);

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'payment_received')->count());
        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->count());
    }

    public function test_partial_fulfillment_money_invariant_paid_equals_delivered_plus_refunded(): void
    {
        ProductKey::query()->where('sku', 'SUB-SPOTIFY-1M')->delete();

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME'],
                ['sku' => 'KEY-GTA5'],
                ['sku' => 'SUB-SPOTIFY-1M'],
            ],
        ])->json('data.id');

        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_money_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'partially_delivered');

        $paid = (float) FinancialLedgerEntry::query()->where('event_type', 'payment_received')->sum('amount');
        $delivered = (float) FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->sum('amount');
        $refunded = (float) FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->sum('amount');

        $this->assertEqualsWithDelta($amount, $paid, 0.001);
        $this->assertEqualsWithDelta(1290 + 1990, $delivered, 0.001);
        $this->assertEqualsWithDelta(299, $refunded, 0.001);
        $this->assertEqualsWithDelta($paid, $delivered + $refunded, 0.001);

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertOk()
            ->assertJsonPath('ledger_balanced', true)
            ->assertJsonPath('ledger_refund_sum', 299);
    }

    public function test_full_refund_when_no_stock_and_idempotent_refund(): void
    {
        ProductKey::query()->where('sku', 'SUB-SPOTIFY-1M')->delete();

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'SUB-SPOTIFY-1M'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_full_refund',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 299,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'refunded');

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->count());

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover')
            ->assertOk();

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->count());
        $this->getJson('/api/v1/orders/'.$orderId)->assertJsonPath('data.status', 'refunded');
    }

    public function test_recover_stale_delivering_after_keys_available(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_stale_pay',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)->assertJsonPath('data.status', 'delivered');

        \App\Models\Order\Order::query()->where('public_id', $orderId)->update([
            'status' => 'delivering',
            'updated_at' => now()->subMinutes(30),
        ]);

        ProductKey::query()->create([
            'sku' => 'KEY-CS2-PRIME',
            'code' => 'EXTRA-KEY-RECOVER',
            'status' => ProductKeyStatus::Available,
        ]);

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover?stale_minutes=10')
            ->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'delivered');
    }
}
