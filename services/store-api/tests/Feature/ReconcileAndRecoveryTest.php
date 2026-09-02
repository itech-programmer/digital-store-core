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

        Http::fake([
            '*/issue' => Http::response([
                'status' => 'ok',
                'request_id' => 'ignored',
                'code' => 'SUPPLIER-CODE',
            ], 200),
        ]);
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
            ->assertJsonPath('ledger_balanced', true);

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'payment_received')->count());
        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->count());
    }

    public function test_recover_stuck_out_of_stock_after_keys_refilled(): void
    {
        ProductKey::query()->where('sku', 'SUB-SPOTIFY-1M')->delete();

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'SUB-SPOTIFY-1M'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_oos_recover',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 299,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'out_of_stock');

        $reconcile = $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertOk()
            ->json();

        $this->assertContains($orderId, $reconcile['paid_not_delivered']);

        ProductKey::query()->create([
            'sku' => 'SUB-SPOTIFY-1M',
            'code' => 'RECOVER-KEY-001',
            'status' => ProductKeyStatus::Available,
        ]);

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover')
            ->assertOk()
            ->assertJsonPath('recovered', 1);

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.issued_code', 'RECOVER-KEY-001');
    }
}
