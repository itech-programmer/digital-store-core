<?php

namespace App\Services\Delivery;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Enums\OrderStatus;
use App\Models\Catalog\ProductKey;
use App\Models\Order\DeliveryAttempt;
use App\Models\Order\Order;
use App\Services\Order\ProductKeyInventory;
use App\Services\Supplier\SupplierChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryService implements DeliveryServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly ProductKeyInventory $inventory,
        private readonly SupplierChain $suppliers,
        private readonly LedgerWriterInterface $ledger,
    ) {}

    public function deliver(string $orderPublicId): void
    {
        DB::transaction(function () use ($orderPublicId) {
            $order = $this->orders->findByPublicIdForUpdate($orderPublicId);

            if ($order === null || $order->status === OrderStatus::Delivered) {
                return;
            }

            $primaryRequestId = sprintf('req_%s-1', $order->public_id);

            $existingSuccess = DeliveryAttempt::query()
                ->where('order_id', $order->id)
                ->where('status', 'success')
                ->lockForUpdate()
                ->first();

            if ($existingSuccess?->code) {
                $order->issued_code = $existingSuccess->code;
                $order->delivered_at = $order->delivered_at ?? now();
                $order->status = OrderStatus::Delivered;
                $order->version++;
                $this->orders->save($order);
                $this->ledger->recordDeliveryCompleted($order);

                return;
            }

            $claimed = Order::query()
                ->where('id', $order->id)
                ->whereIn('status', [
                    OrderStatus::Paid->value,
                    OrderStatus::OutOfStock->value,
                    OrderStatus::DeliveryFailed->value,
                ])
                ->update([
                    'status' => OrderStatus::Delivering->value,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                Log::info('delivery.claim_lost', ['order_id' => $orderPublicId]);

                return;
            }

            $order->refresh();

            $key = $this->inventory->reserve($order->sku, $order->id);

            if ($key === null) {
                $order->status = OrderStatus::OutOfStock;
                $order->version++;
                $this->orders->save($order);

                Log::info('delivery.out_of_stock', [
                    'order_id' => $order->public_id,
                    'sku' => $order->sku,
                ]);

                return;
            }

            DeliveryAttempt::query()->firstOrCreate(
                ['request_id' => $primaryRequestId],
                [
                    'order_id' => $order->id,
                    'supplier' => 'primary',
                    'attempt_number' => 1,
                    'status' => 'pending',
                    'started_at' => now(),
                ]
            );

            $outcome = $this->suppliers->issueWithFallback($order->public_id, $order->sku);
            $result = $outcome['result'];
            $supplierName = $outcome['supplier'];
            $requestId = $outcome['request_id'];

            $attemptNumber = str_ends_with($requestId, '-2') ? 2 : 1;

            $attempt = DeliveryAttempt::query()->firstOrCreate(
                ['request_id' => $requestId],
                [
                    'order_id' => $order->id,
                    'supplier' => $supplierName,
                    'attempt_number' => $attemptNumber,
                    'status' => 'pending',
                    'started_at' => now(),
                ]
            );

            if ($result->success) {
                $this->complete($order, $key, $attempt, $key->code, $requestId, $supplierName);

                return;
            }

            $status = $result->reason === 'out_of_stock'
                ? OrderStatus::OutOfStock
                : OrderStatus::DeliveryFailed;

            $this->failAttempt($order, $key, $attempt, $result->reason ?? 'error', $status, $requestId);
        });
    }

    private function failAttempt(
        Order $order,
        ProductKey $key,
        DeliveryAttempt $attempt,
        string $reason,
        OrderStatus $orderStatus,
        string $requestId,
    ): void {
        $this->inventory->release($key);

        $attempt->status = $reason === 'timeout' ? 'timeout' : 'error';
        $attempt->error_reason = $reason;
        $attempt->finished_at = now();
        $attempt->save();

        $order->status = $orderStatus;
        $order->version++;
        $this->orders->save($order);

        Log::warning('delivery.failed', [
            'order_id' => $order->public_id,
            'request_id' => $requestId,
            'reason' => $reason,
        ]);
    }

    private function complete(
        Order $order,
        ProductKey $key,
        DeliveryAttempt $attempt,
        string $code,
        string $requestId,
        string $supplierName,
    ): void {
        $this->inventory->markDelivered($key);

        $attempt->status = 'success';
        $attempt->code = $code;
        $attempt->error_reason = null;
        $attempt->finished_at = now();
        $attempt->save();

        if (str_ends_with($requestId, '-2')) {
            DeliveryAttempt::query()
                ->where('order_id', $order->id)
                ->where('request_id', sprintf('req_%s-1', $order->public_id))
                ->where('status', 'pending')
                ->update([
                    'status' => 'error',
                    'error_reason' => 'fallback_used',
                    'finished_at' => now(),
                ]);
        }

        $order->issued_code = $code;
        $order->delivered_at = now();
        $order->status = OrderStatus::Delivered;
        $order->version++;
        $this->orders->save($order);

        $this->ledger->recordDeliveryCompleted($order);

        Log::info('delivery.completed', [
            'order_id' => $order->public_id,
            'request_id' => $requestId,
            'supplier' => $supplierName,
        ]);
    }
}
