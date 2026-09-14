<?php

namespace Tests\Feature;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use App\Models\Payment\FinancialLedgerEntry;
use App\Models\Supplier\SupplierIssuance;
use App\Services\Supplier\SupplierChain;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UntrustedSupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_duplicate_supplier_code_is_rejected_and_refunded(): void
    {
        SupplierIssuance::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'request_id' => 'req_seed_dup',
            'supplier' => 'primary',
            'order_item_id' => $this->seedForeignItemId(),
            'sku' => 'KEY-CS2-PRIME',
            'code' => 'DUP-CODE-0001',
            'status' => 'issued',
            'created_at' => now(),
        ]);

        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('DUP-CODE-0001');
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
                return SupplierIssueResultDto::error('no_fallback');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, 1, [0]));

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_dup_code',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.items.0.issued_code', null);

        $this->assertSame(1, FinancialLedgerEntry::query()->where('event_type', 'refund_issued')->count());
        $this->assertDatabaseHas('supplier_issuances', [
            'status' => 'rejected_duplicate',
        ]);
    }

    public function test_wrong_supplier_code_is_rejected_and_refunded(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('WRONG-OTHER-SKU');
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
                return SupplierIssueResultDto::error('no');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, 1, [0]));

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_wrong_code',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'refunded');

        $this->assertDatabaseHas('supplier_issuances', [
            'status' => 'rejected_wrong_code',
        ]);
    }

    public function test_lie_error_then_lookup_delivers_once_without_second_issue(): void
    {
        $issueCalls = 0;

        $primary = new class($issueCalls) implements SupplierClientInterface {
            public function __construct(private int &$issueCalls) {}

            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                $this->issueCalls++;

                return SupplierIssueResultDto::error('supplier_error');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return 'LIE-OK-CODE-01';
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('SHOULD-NOT-USE');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, 1, [0]));

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_lie_1',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.issued_code', 'LIE-OK-CODE-01')
            ->assertJsonPath('data.items.0.issued_code', 'LIE-OK-CODE-01');

        $this->assertSame(1, $issueCalls);
        $this->assertSame(1, SupplierIssuance::query()->where('status', 'issued')->where('code', 'LIE-OK-CODE-01')->count());
    }

    public function test_foreign_sku_without_wrong_prefix_is_rejected(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('SEEMS-VALID-01', 'OTHER-SKU');
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
                return SupplierIssueResultDto::error('no');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $this->bindSupplierChain(new SupplierChain($primary, $fallback, 1, [0]));

        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-GTA5'])->json('data.id');
        $this->postJson('/api/v1/webhook/payment', [
            'event_id' => 'evt_sku_mismatch',
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => 1990,
            'currency' => 'RUB',
            'created_at' => '2025-01-01T12:00:00Z',
        ])->assertOk();

        $this->getJson('/api/v1/orders/'.$orderId)
            ->assertJsonPath('data.status', 'refunded');

        $this->assertDatabaseHas('supplier_issuances', [
            'status' => 'rejected_wrong_code',
        ]);
    }

    private function seedForeignItemId(): string
    {
        $orderId = $this->postJson('/api/v1/orders', ['sku' => 'KEY-CS2-PRIME'])->json('data.id');

        return \App\Models\Order\OrderItem::query()
            ->where('order_id', \App\Models\Order\Order::query()->where('public_id', $orderId)->value('id'))
            ->value('id');
    }
}
