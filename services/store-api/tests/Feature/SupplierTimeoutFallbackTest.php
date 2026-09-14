<?php

namespace Tests\Feature;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use App\Models\Order\DeliveryAttempt;
use App\Models\Order\Order;
use App\Services\Supplier\SupplierChain;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTimeoutFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_timeout_then_same_request_id_retry_delivers_once(): void
    {
        $seenRequestIds = [];

        $primary = new class($seenRequestIds) implements SupplierClientInterface {
            private int $calls = 0;

            public function __construct(private array &$seenRequestIds) {}

            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                $this->calls++;
                $this->seenRequestIds[] = $request->requestId;

                if ($this->calls === 1) {
                    return SupplierIssueResultDto::timeout();
                }

                return SupplierIssueResultDto::ok('IGNORED-SUPPLIER-CODE');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('should_not_run');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, maxRetries: 3, backoffMs: [0, 0, 0])
        );

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_timeout_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertCount(2, $seenRequestIds);
        $this->assertSame($seenRequestIds[0], $seenRequestIds[1]);
        $this->assertMatchesRegularExpression(
            '/^req_'.preg_quote($orderId, '/').'_[a-f0-9]+-1$/',
            $seenRequestIds[0]
        );
        $this->assertSame(
            1,
            DeliveryAttempt::query()->where('order_id', Order::where('public_id', $orderId)->value('id'))
                ->where('status', 'success')
                ->count()
        );
    }

    public function test_fallback_to_fallback_when_primary_unavailable(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('supplier_unavailable');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('FROM-FALLBACK');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, maxRetries: 2, backoffMs: [0])
        );

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $amount = $this->getJson('/api/v1/orders/'.$orderId)->json('data.amount');

        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_fallback_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $internalId = Order::query()->where('public_id', $orderId)->value('id');

        $success = DeliveryAttempt::query()
            ->where('order_id', $internalId)
            ->where('status', 'success')
            ->first();

        $this->assertNotNull($success);
        $this->assertSame('fallback', $success->supplier);
        $this->assertMatchesRegularExpression(
            '/^req_'.preg_quote($orderId, '/').'_[a-f0-9]+-2$/',
            $success->request_id
        );

        $this->assertSame(
            1,
            DeliveryAttempt::query()->where('order_id', $internalId)->where('status', 'success')->count()
        );
    }
}
