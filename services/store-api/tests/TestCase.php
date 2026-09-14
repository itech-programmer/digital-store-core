<?php

namespace Tests;

use App\Contracts\Supplier\SupplierChainInterface;
use App\Services\Supplier\SupplierChain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    protected function bindSupplierChain(SupplierChain $chain): void
    {
        $this->app->instance(SupplierChain::class, $chain);
        $this->app->instance(SupplierChainInterface::class, $chain);
    }

    protected function clearSupplierChainBinding(): void
    {
        $this->app->forgetInstance(SupplierChain::class);
        $this->app->forgetInstance(SupplierChainInterface::class);
    }

    protected function fakeHonestSupplier(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_contains($url, '/issuances/')) {
                return Http::response(['error' => 'not_found'], 404);
            }

            if (! str_contains($url, '/issue')) {
                return Http::response(['error' => 'unexpected'], 404);
            }

            $data = $request->data();
            $rid = (string) ($data['request_id'] ?? uniqid('r', true));
            $hash = strtoupper(substr(md5($rid), 0, 12));
            $code = substr($hash, 0, 4).'-'.substr($hash, 4, 4).'-'.substr($hash, 8, 4);

            return Http::response([
                'status' => 'ok',
                'request_id' => $rid,
                'code' => $code,
                'sku' => $data['sku'] ?? null,
            ], 200);
        });
    }
}
