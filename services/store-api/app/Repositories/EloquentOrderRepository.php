<?php

namespace App\Repositories;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function findByPublicId(string $publicId): ?Order
    {
        return Order::query()
            ->with('items')
            ->where('public_id', $publicId)
            ->first();
    }

    public function findByPublicIdForUpdate(string $publicId): ?Order
    {
        return Order::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    public function findByIdForUpdate(string $id): ?Order
    {
        return Order::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    public function create(array $attributes): Order
    {
        $order = new Order($attributes);
        $order->save();

        return $order;
    }

    public function save(Order $order): void
    {
        $order->save();
    }

    public function loadItemsOrdered(Order $order): Order
    {
        $order->load(['items' => fn ($q) => $q->orderBy('position')->orderBy('id')]);

        return $order;
    }

    public function freshWithItems(Order $order): Order
    {
        return $order->fresh(['items']) ?? $order;
    }

    public function fresh(Order $order): ?Order
    {
        return $order->fresh();
    }

    public function claimForDelivery(string $orderId, array $fromStatuses): int
    {
        return Order::query()
            ->where('id', $orderId)
            ->whereIn('status', $fromStatuses)
            ->update([
                'status' => OrderStatus::Delivering->value,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function countByStatus(string $status): int
    {
        return Order::query()->where('status', $status)->count();
    }

    public function findPublicIdById(string $id): ?string
    {
        $value = Order::query()->where('id', $id)->value('public_id');

        return $value !== null ? (string) $value : null;
    }

    public function listStaleDelivering(int $staleMinutes): Collection
    {
        return Order::query()
            ->where('status', OrderStatus::Delivering->value)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->orderBy('id')
            ->get();
    }

    public function listRecoveryCandidates(): Collection
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->whereHas('items', function ($q) {
                $q->whereNotIn('status', [
                    OrderItemStatus::Delivered->value,
                    OrderItemStatus::Refunded->value,
                ]);
            })
            ->orderBy('id')
            ->get();
    }

    public function listPaidNotDeliveredPublicIds(): array
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Delivering->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('processed_webhook_events')
                    ->whereColumn('processed_webhook_events.order_id', 'orders.id')
                    ->where('processed_webhook_events.status', 'paid');
            })
            ->orderBy('public_id')
            ->pluck('public_id')
            ->all();
    }

    public function listDeliveredNotPaidPublicIds(): array
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Delivered->value,
                OrderStatus::PartiallyDelivered->value,
            ])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('processed_webhook_events')
                    ->whereColumn('processed_webhook_events.order_id', 'orders.id')
                    ->where('processed_webhook_events.status', 'paid');
            })
            ->orderBy('public_id')
            ->pluck('public_id')
            ->all();
    }
}
