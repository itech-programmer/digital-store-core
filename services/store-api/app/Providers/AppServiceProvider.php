<?php

namespace App\Providers;

use App\Contracts\Admin\DeliveryProgressServiceInterface;
use App\Contracts\Admin\PointInTimeServiceInterface;
use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Contracts\Catalog\ProductStockCacheRepositoryInterface;
use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Contracts\Event\DomainEventRecorderInterface;
use App\Contracts\Event\DomainEventRepositoryInterface;
use App\Contracts\Order\DeliveryAttemptRepositoryInterface;
use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Order\OrderServiceInterface;
use App\Contracts\Order\ProductKeyInventoryInterface;
use App\Contracts\Order\RecoveryServiceInterface;
use App\Contracts\Payment\FinancialLedgerRepositoryInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Contracts\Payment\PaymentWebhookRepositoryInterface;
use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\Contracts\Payment\ReconcileServiceInterface;
use App\Contracts\Supplier\SupplierChainInterface;
use App\Contracts\Supplier\SupplierClientInterface;
use App\Contracts\Supplier\SupplierIssuanceRepositoryInterface;
use App\Contracts\Supplier\SupplierIssuanceServiceInterface;
use App\Repositories\EloquentDeliveryAttemptRepository;
use App\Repositories\EloquentDomainEventRepository;
use App\Repositories\EloquentFinancialLedgerRepository;
use App\Repositories\EloquentOrderItemRepository;
use App\Repositories\EloquentOrderRepository;
use App\Repositories\EloquentPaymentWebhookRepository;
use App\Repositories\EloquentProductKeyRepository;
use App\Repositories\EloquentProductRepository;
use App\Repositories\EloquentProductStockCacheRepository;
use App\Repositories\EloquentSupplierIssuanceRepository;
use App\Services\Admin\DeliveryProgressService;
use App\Services\Admin\PointInTimeService;
use App\Services\Catalog\StockCacheService;
use App\Services\Delivery\DeliveryService;
use App\Services\Event\DomainEventRecorder;
use App\Services\Finance\LedgerWriter;
use App\Services\Finance\ReconcileService;
use App\Services\Order\OrderService;
use App\Services\Order\ProductKeyInventory;
use App\Services\Order\RecoveryService;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Supplier\HttpSupplierClient;
use App\Services\Supplier\SupplierChain;
use App\Services\Supplier\SupplierIssuanceService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(OrderItemRepositoryInterface::class, EloquentOrderItemRepository::class);
        $this->app->bind(DeliveryAttemptRepositoryInterface::class, EloquentDeliveryAttemptRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(ProductKeyRepositoryInterface::class, EloquentProductKeyRepository::class);
        $this->app->bind(ProductStockCacheRepositoryInterface::class, EloquentProductStockCacheRepository::class);
        $this->app->bind(SupplierIssuanceRepositoryInterface::class, EloquentSupplierIssuanceRepository::class);
        $this->app->bind(FinancialLedgerRepositoryInterface::class, EloquentFinancialLedgerRepository::class);
        $this->app->bind(DomainEventRepositoryInterface::class, EloquentDomainEventRepository::class);
        $this->app->bind(PaymentWebhookRepositoryInterface::class, EloquentPaymentWebhookRepository::class);

        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->app->bind(PaymentWebhookServiceInterface::class, PaymentWebhookService::class);
        $this->app->bind(DeliveryServiceInterface::class, DeliveryService::class);
        $this->app->bind(ReconcileServiceInterface::class, ReconcileService::class);
        $this->app->bind(RecoveryServiceInterface::class, RecoveryService::class);
        $this->app->bind(StockCacheServiceInterface::class, StockCacheService::class);
        $this->app->bind(LedgerWriterInterface::class, LedgerWriter::class);
        $this->app->bind(DeliveryProgressServiceInterface::class, DeliveryProgressService::class);
        $this->app->bind(PointInTimeServiceInterface::class, PointInTimeService::class);
        $this->app->bind(ProductKeyInventoryInterface::class, ProductKeyInventory::class);
        $this->app->bind(SupplierIssuanceServiceInterface::class, SupplierIssuanceService::class);
        $this->app->singleton(DomainEventRecorderInterface::class, DomainEventRecorder::class);

        $this->app->bind('supplier.primary', function () {
            return new HttpSupplierClient(
                name: 'primary',
                baseUrl: (string) config('suppliers.primary.url'),
                timeoutSeconds: (int) config('suppliers.http_timeout', 5),
                clientRateLimitPerMinute: (int) config('suppliers.client_rate_limit_per_minute', 0),
            );
        });

        $this->app->bind('supplier.fallback', function () {
            return new HttpSupplierClient(
                name: 'fallback',
                baseUrl: (string) config('suppliers.fallback.url'),
                timeoutSeconds: (int) config('suppliers.http_timeout', 5),
                clientRateLimitPerMinute: (int) config('suppliers.client_rate_limit_per_minute', 0),
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
        $this->app->bind(SupplierChainInterface::class, fn ($app) => $app->make(SupplierChain::class));

        $this->app->bind(SupplierClientInterface::class, fn ($app) => $app->make('supplier.primary'));
    }

    public function boot(): void
    {
    }
}
