<?php

namespace Tests\Feature\Api;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminApiTest extends TestCase
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

    public function test_reconcile_rejects_missing_token(): void
    {
        $this->getJson('/api/v1/admin/reconcile')->assertUnauthorized();
    }

    public function test_reconcile_rejects_wrong_token(): void
    {
        $this->withHeader('X-Admin-Token', 'wrong-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertUnauthorized();
    }

    public function test_recover_rejects_missing_token(): void
    {
        $this->postJson('/api/v1/admin/recover')->assertUnauthorized();
    }

    public function test_recover_rejects_wrong_token(): void
    {
        $this->withHeader('X-Admin-Token', 'wrong-token')
            ->postJson('/api/v1/admin/recover')
            ->assertUnauthorized();
    }

    public function test_recover_returns_structure_with_valid_token(): void
    {
        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->postJson('/api/v1/admin/recover?stale_minutes=5')
            ->assertOk()
            ->assertJsonStructure([
                'recovered',
                'order_ids',
            ])
            ->assertJsonPath('recovered', 0)
            ->assertJsonPath('order_ids', []);
    }

    public function test_reconcile_returns_structure_on_empty_database(): void
    {
        $this->withHeader('X-Admin-Token', 'dev-admin-token')
            ->getJson('/api/v1/admin/reconcile')
            ->assertOk()
            ->assertJsonStructure([
                'paid_not_delivered',
                'delivered_not_paid',
                'ledger_balanced',
                'ledger_payment_sum',
                'ledger_delivery_sum',
                'generated_at',
            ])
            ->assertJsonPath('paid_not_delivered', [])
            ->assertJsonPath('delivered_not_paid', [])
            ->assertJsonPath('ledger_balanced', true);
    }
}
