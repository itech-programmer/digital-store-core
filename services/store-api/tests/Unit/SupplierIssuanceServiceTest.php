<?php

namespace Tests\Unit;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Services\Supplier\SupplierIssuanceService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_rejects_wrong_prefix_and_sku_mismatch(): void
    {
        $service = app(SupplierIssuanceService::class);
        $item = $this->makeItem('KEY-GTA5');

        $this->assertFalse($service->isAcceptableCode('WRONG-ABC'));
        $this->assertFalse($service->isAcceptableCode('FINE-CODE', 'KEY-GTA5', 'OTHER-SKU'));
        $this->assertTrue($service->isAcceptableCode('FINE-CODE', 'KEY-GTA5', 'KEY-GTA5'));
        $this->assertTrue($service->isAcceptableCode('FINE-CODE', 'KEY-GTA5', null));

        $rejected = $service->registerIssued(
            requestId: 'req_sku_mismatch',
            supplier: 'primary',
            item: $item,
            code: 'LOOKS-FINE-01',
            responseSku: 'OTHER-SKU',
        );

        $this->assertFalse($rejected['ok']);
        $this->assertSame('wrong_code', $rejected['reason']);
    }

    private function makeItem(string $sku): OrderItem
    {
        $order = Order::query()->create([
            'public_id' => 'ord_iss_'.uniqid(),
            'sku' => $sku,
            'amount' => 1990,
            'currency' => 'RUB',
            'status' => 'created',
        ]);

        return OrderItem::query()->create([
            'order_id' => $order->id,
            'sku' => $sku,
            'quantity' => 1,
            'unit_price' => 1990,
            'currency' => 'RUB',
            'status' => 'pending',
            'position' => 0,
            'supplier' => 'primary',
        ]);
    }
}
