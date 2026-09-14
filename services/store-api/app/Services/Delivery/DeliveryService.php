<?php

namespace App\Services\Delivery;

use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Contracts\Event\DomainEventRecorderInterface;
use App\Contracts\Order\DeliveryAttemptRepositoryInterface;
use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Order\ProductKeyInventoryInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Contracts\Supplier\SupplierChainInterface;
use App\Contracts\Supplier\SupplierIssuanceServiceInterface;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Exceptions\SupplierRateLimitedException;
use App\Models\Catalog\ProductKey;
use App\Models\Order\DeliveryAttempt;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryService implements DeliveryServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderItemRepositoryInterface $orderItems,
        private readonly DeliveryAttemptRepositoryInterface $deliveryAttempts,
        private readonly ProductKeyRepositoryInterface $productKeys,
        private readonly ProductKeyInventoryInterface $inventory,
        private readonly SupplierChainInterface $suppliers,
        private readonly LedgerWriterInterface $ledger,
        private readonly SupplierIssuanceServiceInterface $issuances,
        private readonly DomainEventRecorderInterface $events,
    ) {}

    public function deliver(string $orderPublicId): void
    {
        $itemIds = $this->claimOrderAndListItems($orderPublicId);
        if ($itemIds === null) {
            return;
        }

        foreach ($itemIds as $itemId) {
            $this->deliverItemOutsideLongTxn($orderPublicId, $itemId);
        }

        DB::transaction(fn () => $this->refreshOrderAggregateByPublicId($orderPublicId));
    }

    private function claimOrderAndListItems(string $orderPublicId): ?array
    {
        return DB::transaction(function () use ($orderPublicId) {
            $order = $this->orders->findByPublicIdForUpdate($orderPublicId);

            if ($order === null || $order->status->isFinal()) {
                return null;
            }

            $order = $this->orders->loadItemsOrdered($order);

            if ($order->items->isEmpty()) {
                Log::warning('delivery.no_items', ['order_id' => $orderPublicId]);

                return null;
            }

            $claimed = $this->orders->claimForDelivery($order->id, [
                OrderStatus::Paid->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ]);

            if ($claimed === 0 && $order->status !== OrderStatus::Delivering) {
                Log::info('delivery.claim_lost', ['order_id' => $orderPublicId]);

                return null;
            }

            return $order->items
                ->filter(fn (OrderItem $item) => ! $item->status->isTerminal())
                ->pluck('id')
                ->values()
                ->all();
        });
    }

    private function deliverItemOutsideLongTxn(string $orderPublicId, string $itemId): void
    {
        $prepared = DB::transaction(function () use ($orderPublicId, $itemId) {
            $order = $this->orders->findByPublicIdForUpdate($orderPublicId);
            if ($order === null || $order->status->isFinal()) {
                return null;
            }

            $item = $this->orderItems->findByIdForUpdate($itemId);
            if ($item === null || $item->status->isTerminal()) {
                return null;
            }

            $existingSuccess = $this->deliveryAttempts->findSuccessByOrderItemIdForUpdate($item->id);

            if ($existingSuccess?->code) {
                $item->issued_code = $existingSuccess->code;
                $item->supplier = $existingSuccess->supplier;
                $item->delivered_at = $item->delivered_at ?? now();
                $item->status = OrderItemStatus::Delivered;
                $item->version++;
                $this->orderItems->save($item);
                $this->ledger->recordDeliveryCompleted($order, $item);

                return null;
            }

            $existingIssuance = $this->issuances->findByRequestId(
                $this->preferredRequestId($order->public_id, $item->id)
            );
            if ($existingIssuance?->status === 'issued' && $existingIssuance->code) {
                $key = $this->inventory->reserve($item->sku, $order->id, $item->id);
                if ($key === null) {
                    $item->status = OrderItemStatus::OutOfStock;
                    $item->version++;
                    $this->orderItems->save($item);

                    return null;
                }

                $attempt = $this->deliveryAttempts->firstOrCreateByRequestId(
                    $existingIssuance->request_id,
                    [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'supplier' => $existingIssuance->supplier,
                        'attempt_number' => 1,
                        'status' => 'pending',
                        'started_at' => now(),
                    ]
                );
                $this->completeItem($order, $item, $key, $attempt, $existingIssuance->code, $existingIssuance->request_id, $existingIssuance->supplier);

                return null;
            }

            $claimed = $this->orderItems->claimForDelivery($item->id, [
                OrderItemStatus::Pending->value,
                OrderItemStatus::Failed->value,
                OrderItemStatus::OutOfStock->value,
                OrderItemStatus::DeliveryFailed->value,
            ]);

            if ($claimed === 0) {
                return null;
            }

            $item = $this->orderItems->refresh($item);
            $key = $this->inventory->reserve($item->sku, $order->id, $item->id);
            if ($key === null) {
                $item->status = OrderItemStatus::OutOfStock;
                $item->version++;
                $this->orderItems->save($item);

                return null;
            }

            $item = $this->orderItems->loadProduct($item);
            $preferred = $item->supplier ?: ($item->product?->preferred_supplier ?? 'primary');
            $preferred = $preferred === 'fallback' ? 'fallback' : 'primary';

            return [
                'order_id' => $order->id,
                'order_public_id' => $order->public_id,
                'item_id' => $item->id,
                'sku' => $item->sku,
                'key_id' => $key->id,
                'preferred' => $preferred,
            ];
        });

        if ($prepared === null) {
            return;
        }

        $outcome = $this->suppliers->issueForItem(
            orderPublicId: $prepared['order_public_id'],
            sku: $prepared['sku'],
            orderItemId: $prepared['item_id'],
            preferredSupplier: $prepared['preferred'],
        );

        $rateLimitedSeconds = null;

        DB::transaction(function () use ($prepared, $outcome, &$rateLimitedSeconds) {
            $order = $this->orders->findByIdForUpdate($prepared['order_id']);
            $item = $this->orderItems->findByIdForUpdate($prepared['item_id']);
            $key = $this->productKeys->findByIdForUpdate($prepared['key_id']);

            if ($order === null || $item === null || $key === null) {
                return;
            }

            if ($item->status->isTerminal()) {
                return;
            }

            $result = $outcome['result'];
            $supplierName = $outcome['supplier'];
            $requestId = $outcome['request_id'];
            $attemptNumber = str_ends_with($requestId, '-2') ? 2 : 1;

            $preferredRequestId = $this->preferredRequestId($order->public_id, $item->id);

            $this->deliveryAttempts->firstOrCreateByRequestId(
                $preferredRequestId,
                [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'supplier' => $prepared['preferred'],
                    'attempt_number' => 1,
                    'status' => 'pending',
                    'started_at' => now(),
                ]
            );

            $attempt = $this->deliveryAttempts->firstOrCreateByRequestId(
                $requestId,
                [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'supplier' => $supplierName,
                    'attempt_number' => $attemptNumber,
                    'status' => 'pending',
                    'started_at' => now(),
                ]
            );

            if ($result->rateLimited) {
                $this->inventory->release($key);
                $attempt->status = 'error';
                $attempt->error_reason = 'rate_limited';
                $attempt->finished_at = now();
                $this->deliveryAttempts->save($attempt);

                $item->status = OrderItemStatus::Pending;
                $item->version++;
                $this->orderItems->save($item);

                $rateLimitedSeconds = $result->retryAfterSeconds ?? 60;

                return;
            }

            if ($result->success && is_string($result->code) && $result->code !== '') {
                $registered = $this->issuances->registerIssued(
                    requestId: $requestId,
                    supplier: $supplierName,
                    item: $item,
                    code: $result->code,
                    rawResponse: [
                        'code' => $result->code,
                        'sku' => $result->responseSku,
                    ],
                    responseSku: $result->responseSku,
                );

                if ($registered['ok'] === true) {
                    $code = $registered['issuance']->code ?? $result->code;
                    $this->completeItem($order, $item, $key, $attempt, $code, $requestId, $supplierName);

                    return;
                }

                $this->failItem(
                    $order,
                    $item,
                    $key,
                    $attempt,
                    $registered['reason'] ?? 'invalid_code',
                    OrderItemStatus::DeliveryFailed,
                    $requestId,
                );

                return;
            }

            $status = $result->reason === 'out_of_stock'
                ? OrderItemStatus::OutOfStock
                : OrderItemStatus::DeliveryFailed;

            $this->failItem($order, $item, $key, $attempt, $result->reason ?? 'error', $status, $requestId);
        });

        if ($rateLimitedSeconds !== null) {
            throw new SupplierRateLimitedException($rateLimitedSeconds);
        }
    }

    private function preferredRequestId(string $orderPublicId, string $orderItemId): string
    {
        return sprintf('req_%s_%s-1', $orderPublicId, str_replace('-', '', $orderItemId));
    }

    private function failItem(
        Order $order,
        OrderItem $item,
        ProductKey $key,
        DeliveryAttempt $attempt,
        string $reason,
        OrderItemStatus $itemStatus,
        string $requestId,
    ): void {
        $this->inventory->release($key);

        $attempt->status = $reason === 'timeout' ? 'timeout' : 'error';
        $attempt->error_reason = $reason;
        $attempt->finished_at = now();
        $this->deliveryAttempts->save($attempt);

        $item->status = $itemStatus;
        $item->version++;
        $this->orderItems->save($item);

        Log::warning('delivery.item_failed', [
            'order_id' => $order->public_id,
            'order_item_id' => $item->id,
            'request_id' => $requestId,
            'reason' => $reason,
        ]);
    }

    private function completeItem(
        Order $order,
        OrderItem $item,
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
        $this->deliveryAttempts->save($attempt);

        if (str_ends_with($requestId, '-2')) {
            $preferredRequestId = substr($requestId, 0, -1).'1';
            $this->deliveryAttempts->markPendingAsFallbackUsed($item->id, $preferredRequestId);
        }

        $item->issued_code = $code;
        $item->supplier = $supplierName;
        $item->delivered_at = now();
        $item->status = OrderItemStatus::Delivered;
        $item->version++;
        $this->orderItems->save($item);

        $this->ledger->recordDeliveryCompleted($order, $item);

        $this->events->record('order_item', $item->id, 'order_item.delivered', [
            'sku' => $item->sku,
            'issued_code' => $code,
            'supplier' => $supplierName,
            'unit_price' => (float) $item->unit_price,
        ]);

        Log::info('delivery.item_completed', [
            'order_id' => $order->public_id,
            'order_item_id' => $item->id,
            'request_id' => $requestId,
            'supplier' => $supplierName,
            'code_source' => 'supplier_issuance',
        ]);
    }

    private function refundItem(Order $order, OrderItem $item): void
    {
        if ($item->status === OrderItemStatus::Refunded) {
            return;
        }

        $item->status = OrderItemStatus::Refunded;
        $item->refunded_at = now();
        $item->version++;
        $this->orderItems->save($item);

        $this->ledger->recordRefundIssued($order, $item);

        $this->events->record('order_item', $item->id, 'order_item.refunded', [
            'sku' => $item->sku,
            'unit_price' => (float) $item->unit_price,
        ]);

        Log::info('delivery.item_refunded', [
            'order_id' => $order->public_id,
            'order_item_id' => $item->id,
            'amount' => $item->lineAmount(),
        ]);
    }

    private function refreshOrderAggregateByPublicId(string $orderPublicId): void
    {
        $order = $this->orders->findByPublicIdForUpdate($orderPublicId);
        if ($order === null || $order->status->isFinal()) {
            return;
        }

        $items = $this->orderItems->listByOrderIdForUpdate($order->id);

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item->status, [
                OrderItemStatus::Failed,
                OrderItemStatus::OutOfStock,
                OrderItemStatus::DeliveryFailed,
            ], true)) {
                $this->refundItem($order, $item);
            }
        }

        $items = $this->orderItems->listByOrderId($order->id);

        $allDelivered = $items->every(fn (OrderItem $i) => $i->status === OrderItemStatus::Delivered);
        $allRefunded = $items->every(fn (OrderItem $i) => $i->status === OrderItemStatus::Refunded);
        $anyDelivered = $items->contains(fn (OrderItem $i) => $i->status === OrderItemStatus::Delivered);
        $anyRefunded = $items->contains(fn (OrderItem $i) => $i->status === OrderItemStatus::Refunded);
        $anyOpen = $items->contains(fn (OrderItem $i) => in_array($i->status, [
            OrderItemStatus::Pending,
            OrderItemStatus::Delivering,
        ], true));

        if ($anyOpen) {
            $order->status = OrderStatus::Delivering;
            $order->version++;
            $this->orders->save($order);

            return;
        }

        if ($allDelivered) {
            $firstCode = $items->first(fn (OrderItem $i) => $i->issued_code !== null)?->issued_code;
            $order->issued_code = $firstCode;
            $order->delivered_at = now();
            $order->status = OrderStatus::Delivered;
            $order->version++;
            $this->orders->save($order);
            $this->events->record('order', $order->id, 'order.status_changed', [
                'status' => OrderStatus::Delivered->value,
                'issued_code' => $firstCode,
            ]);

            return;
        }

        if ($allRefunded) {
            $order->status = OrderStatus::Refunded;
            $order->version++;
            $this->orders->save($order);
            $this->events->record('order', $order->id, 'order.status_changed', [
                'status' => OrderStatus::Refunded->value,
            ]);

            return;
        }

        if ($anyDelivered && $anyRefunded) {
            $firstCode = $items->first(fn (OrderItem $i) => $i->issued_code !== null)?->issued_code;
            $order->issued_code = $firstCode;
            $order->delivered_at = $order->delivered_at ?? now();
            $order->status = OrderStatus::PartiallyDelivered;
            $order->version++;
            $this->orders->save($order);
            $this->events->record('order', $order->id, 'order.status_changed', [
                'status' => OrderStatus::PartiallyDelivered->value,
                'issued_code' => $firstCode,
            ]);

            return;
        }

        $order->status = OrderStatus::DeliveryFailed;
        $order->version++;
        $this->orders->save($order);
        $this->events->record('order', $order->id, 'order.status_changed', [
            'status' => OrderStatus::DeliveryFailed->value,
        ]);
    }
}
