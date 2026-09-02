<?php

namespace App\Repositories;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Models\Order\Order;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function findByPublicId(string $publicId): ?Order
    {
        return Order::query()->where('public_id', $publicId)->first();
    }

    public function findByPublicIdForUpdate(string $publicId): ?Order
    {
        return Order::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    public function save(Order $order): void
    {
        $order->save();
    }
}
