<?php

namespace App\Services\Order;

use App\Contracts\Catalog\ProductKeyRepositoryInterface;
use App\Contracts\Order\DeliveryAttemptRepositoryInterface;
use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Order\ProductKeyInventoryInterface;
use App\Contracts\Order\RecoveryServiceInterface;
use App\Contracts\Supplier\SupplierIssuanceRepositoryInterface;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoveryService implements RecoveryServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderItemRepositoryInterface $orderItems,
        private readonly DeliveryAttemptRepositoryInterface $deliveryAttempts,
        private readonly ProductKeyRepositoryInterface $productKeys,
        private readonly SupplierIssuanceRepositoryInterface $issuances,
        private readonly DeliveryServiceInterface $delivery,
        private readonly ProductKeyInventoryInterface $inventory,
    ) {}

    public function recoverStuck(int $staleMinutes = 10): array
    {
        $recovered = [];

        foreach ($this->orders->listStaleDelivering($staleMinutes) as $order) {
            DB::transaction(function () use ($order) {
                $locked = $this->orders->findByIdForUpdate($order->id);
                if ($locked === null || $locked->status !== OrderStatus::Delivering) {
                    return;
                }

                $items = $this->orderItems->listByOrderIdForUpdate($locked->id);

                foreach ($items as $item) {
                    if ($item->status === OrderItemStatus::Delivered) {
                        continue;
                    }

                    $resumable = $this->lineHasResumableOutcome($locked, $item);

                    if ($item->status === OrderItemStatus::Delivering) {
                        $item->status = OrderItemStatus::Pending;
                        $item->version++;
                        $this->orderItems->save($item);
                    }

                    if ($resumable) {
                        Log::info('recovery.keep_reserved_for_resume', [
                            'order_id' => $locked->public_id,
                            'order_item_id' => $item->id,
                        ]);

                        continue;
                    }

                    $this->productKeys
                        ->listReservedByOrderItemIdForUpdate($item->id)
                        ->each(fn ($key) => $this->inventory->release($key));
                }

                $items = $this->orderItems->listByOrderId($locked->id);

                if ($items->isNotEmpty() && $items->every(fn (OrderItem $item) => $item->status->isTerminal())) {
                    $this->finalizeOrderFromLines($locked, $items);
                } else {
                    $locked->status = OrderStatus::Paid;
                    $locked->version++;
                    $this->orders->save($locked);
                }

                Log::warning('recovery.reset_stale_delivering', [
                    'order_id' => $locked->public_id,
                    'status' => $locked->status->value,
                ]);
            });
        }

        $candidates = $this->orders->listRecoveryCandidates();

        foreach ($candidates as $order) {
            Log::info('recovery.attempt', ['order_id' => $order->public_id, 'status' => $order->status->value]);

            $this->delivery->deliver($order->public_id);

            $fresh = $this->orders->fresh($order);
            if ($fresh && in_array($fresh->status, [
                OrderStatus::Delivered,
                OrderStatus::PartiallyDelivered,
                OrderStatus::Refunded,
            ], true)) {
                $recovered[] = $fresh->public_id;
            }
        }

        return [
            'recovered' => count($recovered),
            'order_ids' => $recovered,
        ];
    }

    private function finalizeOrderFromLines(Order $order, $items): void
    {
        $allDelivered = $items->every(fn (OrderItem $i) => $i->status === OrderItemStatus::Delivered);
        $allRefunded = $items->every(fn (OrderItem $i) => $i->status === OrderItemStatus::Refunded);
        $anyDelivered = $items->contains(fn (OrderItem $i) => $i->status === OrderItemStatus::Delivered);
        $anyRefunded = $items->contains(fn (OrderItem $i) => $i->status === OrderItemStatus::Refunded);

        if ($allDelivered) {
            $order->issued_code = $items->first(fn (OrderItem $i) => $i->issued_code !== null)?->issued_code;
            $order->delivered_at = $order->delivered_at ?? now();
            $order->status = OrderStatus::Delivered;
        } elseif ($allRefunded) {
            $order->status = OrderStatus::Refunded;
        } elseif ($anyDelivered && $anyRefunded) {
            $order->issued_code = $items->first(fn (OrderItem $i) => $i->issued_code !== null)?->issued_code;
            $order->delivered_at = $order->delivered_at ?? now();
            $order->status = OrderStatus::PartiallyDelivered;
        } else {
            $order->status = OrderStatus::Paid;
        }

        $order->version++;
        $this->orders->save($order);
    }

    private function lineHasResumableOutcome(Order $order, OrderItem $item): bool
    {
        if ($this->deliveryAttempts->hasSuccessfulWithCode($item->id)) {
            return true;
        }

        if ($this->issuances->hasIssuedWithCodeForItem($item->id)) {
            return true;
        }

        $token = str_replace('-', '', $item->id);
        $preferredRequestId = sprintf('req_%s_%s-1', $order->public_id, $token);

        return $this->issuances->hasIssuedByRequestId($preferredRequestId);
    }
}
