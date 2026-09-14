<?php

namespace Tests\Feature;

use App\Models\Event\DomainEvent;
use App\Models\Payment\FinancialLedgerEntry;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointInTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->fakeHonestSupplier();
    }

    public function test_finance_period_requires_admin_token(): void
    {
        $this->getJson('/api/v1/admin/finance/period?from='.urlencode(now()->subDay()->toIso8601String()).'&to='.urlencode(now()->toIso8601String()))
            ->assertUnauthorized();
    }

    public function test_as_of_requires_admin_token(): void
    {
        $this->getJson('/api/v1/admin/orders/ord_x/as-of?at='.urlencode(now()->toIso8601String()))
            ->assertUnauthorized();
    }

    public function test_order_as_of_restores_status_and_codes_at_timestamp(): void
    {
        Carbon::setTestNow('2026-09-14 10:00:00');

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME'],
                ['sku' => 'KEY-GTA5'],
            ],
        ])->json('data.id');

        $afterCreate = now()->copy();

        Carbon::setTestNow('2026-09-14 10:01:00');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_asof_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2026-09-14T10:01:00Z',
        ])->assertOk();

        $afterDeliver = now()->copy()->addMinutes(1);

        $live = $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'delivered');

        $codes = collect($live->json('data.items'))->pluck('issued_code')->filter()->values()->all();
        $this->assertCount(2, $codes);

        $createdSnap = $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/orders/'.$orderId.'/as-of?at='.urlencode($afterCreate->toIso8601String()))
            ->assertOk()
            ->json();

        $this->assertSame('created', $createdSnap['order']['status']);
        $this->assertEqualsWithDelta(0.0, (float) $createdSnap['money']['payment_received'], 0.001);
        $this->assertCount(2, $createdSnap['order']['items']);

        $finalSnap = $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/orders/'.$orderId.'/as-of?at='.urlencode($afterDeliver->toIso8601String()))
            ->assertOk()
            ->json();

        $this->assertSame('delivered', $finalSnap['order']['status']);
        $this->assertEqualsWithDelta($amount, $finalSnap['money']['payment_received'], 0.001);
        $this->assertEqualsWithDelta($amount, $finalSnap['money']['delivery_completed'], 0.001);
        $this->assertCount(2, array_filter(array_column($finalSnap['order']['items'], 'issued_code')));

        $this->assertGreaterThan(0, DomainEvent::query()->count());

        Carbon::setTestNow();
    }

    public function test_finance_period_totals_from_ledger(): void
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_period_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2026-09-14T11:00:00Z',
        ])->assertOk();

        $from = now()->subHour()->toIso8601String();
        $to = now()->addHour()->toIso8601String();

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/finance/period?from='.urlencode($from).'&to='.urlencode($to))
            ->assertOk()
            ->assertJsonPath('payment_received', 1990)
            ->assertJsonPath('delivery_completed', 1990)
            ->assertJsonPath('refund_issued', 0)
            ->assertJsonPath('counts.payment_received', 1)
            ->assertJsonPath('counts.delivery_completed', 1);

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'payment_received')->count());
    }
}
