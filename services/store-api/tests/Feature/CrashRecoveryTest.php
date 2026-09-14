<?php

namespace Tests\Feature;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductKeyStatus;
use App\Models\Catalog\ProductKey;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Payment\FinancialLedgerEntry;
use App\Services\Supplier\SupplierChain;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrashRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_kill_mid_delivery_then_recover_reaches_terminal_and_balances(): void
    {
        $state = (object) ['issues' => 0];

        $makeClient = function (string $name) use ($state): SupplierClientInterface {
            return new class($name, $state) implements SupplierClientInterface {
                public function __construct(private string $name, private object $state) {}

                public function name(): string
                {
                    return $this->name;
                }

                public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
                {
                    $this->state->issues++;

                    if ($this->state->issues >= 2) {
                        throw new \RuntimeException('killed mid-delivery');
                    }

                    return SupplierIssueResultDto::ok('CRASH-LINE-'.$this->state->issues);
                }

                public function findIssuedCode(string $requestId): ?string
                {
                    return null;
                }
            };
        };

        $this->bindSupplierChain(new SupplierChain(
            $makeClient('primary'),
            $makeClient('fallback'),
            maxRetries: 1,
            backoffMs: [0],
        ));

        $create = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME'],
                ['sku' => 'KEY-GTA5'],
            ],
        ])->assertCreated();

        $orderId = $create->json('data.id');
        $amount = $create->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_kill_mid',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertStatus(503);

        $order = Order::query()->where('public_id', $orderId)->firstOrFail();
        $this->assertSame(OrderStatus::Delivering, $order->status);

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('position')->get();
        $this->assertCount(2, $items);
        $this->assertSame(OrderItemStatus::Delivered, $items[0]->status);
        $this->assertNotEmpty($items[0]->issued_code);
        $this->assertSame(OrderItemStatus::Delivering, $items[1]->status);
        $this->assertNull($items[1]->issued_code);
        $this->assertTrue(
            ProductKey::query()
                ->where('order_item_id', $items[1]->id)
                ->where('status', ProductKeyStatus::Reserved->value)
                ->exists()
        );

        $order->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        $this->clearSupplierChainBinding();
        $this->fakeHonestSupplier();

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover?stale_minutes=10')
            ->assertOk()
            ->assertJsonPath('recovered', 1);

        $show = $this->getJson('/api/v1/orders/'.$orderId)->assertOk();
        $show->assertJsonPath('data.status', 'delivered')
            ->assertJsonCount(2, 'data.items');

        foreach ($show->json('data.items') as $item) {
            $this->assertSame('delivered', $item['status']);
            $this->assertNotEmpty($item['issued_code']);
        }

        $this->assertCount(2, array_unique(array_column($show->json('data.items'), 'issued_code')));

        $paid = (float) FinancialLedgerEntry::query()->where('event_type', 'payment_received')->sum('amount');
        $delivered = (float) FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->sum('amount');
        $refunded = (float) FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->sum('amount');

        $this->assertEqualsWithDelta($amount, $paid, 0.001);
        $this->assertEqualsWithDelta($paid, $delivered + $refunded, 0.001);
        $this->assertSame(2, FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->count());
        $this->assertSame(0, FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->count());

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertOk()
            ->assertJsonPath('ledger_balanced', true)
            ->assertJsonPath('unbalanced_orders', []);
    }

    public function test_repeat_deliver_and_recover_are_idempotent(): void
    {
        $this->fakeHonestSupplier();

        $orderId = $this->postJson('/api/v1/orders', [
            'items' => [
                ['sku' => 'KEY-CS2-PRIME'],
                ['sku' => 'KEY-GTA5'],
            ],
        ])->json('data.id');

        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_idem_deliver',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)->assertJsonPath('data.status', 'delivered');

        $codesBefore = collect($this->getJson('/api/v1/orders/'.$orderId)->json('data.items'))
            ->pluck('issued_code')
            ->all();

        app(\App\Contracts\Order\DeliveryServiceInterface::class)->deliver($orderId);

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover?stale_minutes=10')
            ->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)->assertJsonPath('data.status', 'delivered');

        $codesAfter = collect($this->getJson('/api/v1/orders/'.$orderId)->json('data.items'))
            ->pluck('issued_code')
            ->all();

        $this->assertSame($codesBefore, $codesAfter);
        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'payment_received')->count());
        $this->assertSame(2, FinancialLedgerEntry::query()->where('event_type', 'delivery_completed')->count());
        $this->assertSame(0, FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->count());
    }
}
