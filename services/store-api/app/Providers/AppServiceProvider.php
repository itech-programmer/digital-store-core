<?php

namespace App\Providers;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Order\OrderServiceInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\Contracts\Payment\ReconcileServiceInterface;
use App\Contracts\Order\RecoveryServiceInterface;
use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Contracts\Supplier\SupplierClientInterface;
use App\Repositories\EloquentOrderRepository;
use App\Repositories\EloquentProductRepository;
use App\Services\Catalog\StockCacheService;
use App\Services\Delivery\DeliveryService;
use App\Services\Finance\LedgerWriter;
use App\Services\Finance\ReconcileService;
use App\Services\Order\OrderService;
use App\Services\Order\RecoveryService;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Supplier\HttpSupplierClient;
use App\Services\Supplier\SupplierChain;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);

        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->app->bind(PaymentWebhookServiceInterface::class, PaymentWebhookService::class);
        $this->app->bind(DeliveryServiceInterface::class, DeliveryService::class);
        $this->app->bind(ReconcileServiceInterface::class, ReconcileService::class);
        $this->app->bind(RecoveryServiceInterface::class, RecoveryService::class);
        $this->app->bind(StockCacheServiceInterface::class, StockCacheService::class);
        $this->app->bind(LedgerWriterInterface::class, LedgerWriter::class);

        $this->app->bind('supplier.primary', function () {
            return new HttpSupplierClient(
                name: 'primary',
                baseUrl: (string) config('suppliers.primary.url'),
                timeoutSeconds: (int) config('suppliers.http_timeout', 5),
            );
        });

        $this->app->bind('supplier.fallback', function () {
            return new HttpSupplierClient(
                name: 'fallback',
                baseUrl: (string) config('suppliers.fallback.url'),
                timeoutSeconds: (int) config('suppliers.http_timeout', 5),
            );
        });

        $this->app->singleton(SupplierChain::class, function ($app) {
            return new SupplierChain(
                primary: $app->make('supplier.primary'),
                fallback: $app->make('supplier.fallback'),
                maxRetries: (int) config('suppliers.max_retries', 3),
                backoffMs: array_map('intval', (array) config('suppliers.backoff_ms', [0, 0, 0])),
            );
        });

        $this->app->bind(SupplierClientInterface::class, fn ($app) => $app->make('supplier.primary'));
    }

    public function boot(): void
    {
    }
}
