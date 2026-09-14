<?php

namespace App\Contracts\Order;

use App\Models\Order\OrderItem;
use Illuminate\Support\Collection;

interface OrderItemRepositoryInterface
{
    public function save(OrderItem $item): void;

    public function create(array $attributes): OrderItem;

    public function findByIdForUpdate(string $id): ?OrderItem;

    public function refresh(OrderItem $item): OrderItem;

    public function loadProduct(OrderItem $item): OrderItem;

    public function claimForDelivery(string $itemId, array $fromStatuses): int;

    public function listByOrderIdForUpdate(string $orderId): Collection;

    public function listByOrderId(string $orderId): Collection;

    public function countByStatus(string $status): int;

    public function countByStatuses(array $statuses): int;
}
