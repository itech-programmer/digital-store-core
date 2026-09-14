<?php

namespace Tests\Feature;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use App\Exceptions\SupplierRateLimitedException;
use App\Services\Supplier\SupplierChain;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_delivery_progress_requires_admin_token(): void
    {
        $this->getJson('/api/v1/admin/delivery-progress')->assertUnauthorized();
    }

    public function test_delivery_progress_returns_counters(): void
    {
        $this->fakeHonestSupplier();

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_prog_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/delivery-progress')
            ->assertOk()
            ->assertJsonStructure([
                'queue_depth',
                'orders_paid_waiting',
                'orders_delivering',
                'orders_delivered',
                'items_delivered',
                'items_pending',
                'generated_at',
            ])
            ->assertJsonPath('orders_delivered', 1)
            ->assertJsonPath('items_delivered', 1);
    }

    public function test_http_429_is_surfaced_as_rate_limited_and_does_not_fail_line(): void
    {
        Http::fake([
            '*/issue' => Http::response(['status' => 'error', 'reason' => 'rate_limited', 'retry_after' => 7], 429, [
                'Retry-After' => '7',
            ]),
            '*/issuances/*' => Http::response(['error' => 'not_found'], 404),
        ]);

        $this->clearSupplierChainBinding();

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_429',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $show = $this->getJson('/api/v1/orders/'.$orderId)->assertOk();
        $this->assertNotSame('refunded', $show->json('data.status'));
        $this->assertNotSame('delivered', $show->json('data.status'));
        $this->assertNull($show->json('data.items.0.issued_code'));
    }

    public function test_both_suppliers_rate_limited_throws_from_direct_deliver(): void
    {
        $limited = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::rateLimited(3);
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $limitedFallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::rateLimited(5);
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($limited, $limitedFallback, 1, [0]));

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');

        $this->expectException(SupplierRateLimitedException::class);

        \App\Models\Order\Order::query()->where('public_id', $orderId)->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        app(\App\Contracts\Order\DeliveryServiceInterface::class)->deliver($orderId);
    }
}
