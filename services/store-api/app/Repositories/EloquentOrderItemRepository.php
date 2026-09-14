<?php

namespace App\Repositories;

use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Enums\OrderItemStatus;
use App\Models\Order\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentOrderItemRepository implements OrderItemRepositoryInterface
{
    public function save(OrderItem $item): void
    {
        $item->save();
    }

    public function create(array $attributes): OrderItem
    {
        $item = new OrderItem($attributes);
        $item->save();

        return $item;
    }

    public function findByIdForUpdate(string $id): ?OrderItem
    {
        return OrderItem::query()
            ->where('id', $id)
            ->lockForUpdate()
            ->first();
    }

    public function refresh(OrderItem $item): OrderItem
    {
        return $item->refresh();
    }

    public function loadProduct(OrderItem $item): OrderItem
    {
        $item->loadMissing('product');

        return $item;
    }

    public function claimForDelivery(string $itemId, array $fromStatuses): int
    {
        return OrderItem::query()
            ->where('id', $itemId)
            ->whereIn('status', $fromStatuses)
            ->update([
                'status' => OrderItemStatus::Delivering->value,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function listByOrderIdForUpdate(string $orderId): Collection
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function listByOrderId(string $orderId): Collection
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function countByStatus(string $status): int
    {
        return OrderItem::query()->where('status', $status)->count();
    }

    public function countByStatuses(array $statuses): int
    {
        return OrderItem::query()->whereIn('status', $statuses)->count();
    }
}
