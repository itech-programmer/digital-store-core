<?php

namespace App\Services\Admin;

use App\Contracts\Admin\DeliveryProgressServiceInterface;
use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Queue;

class DeliveryProgressService implements DeliveryProgressServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderItemRepositoryInterface $orderItems,
    ) {}

    public function progress(): array
    {
        $queueDepth = 0;
        try {
            $queueDepth = Queue::size('delivery');
        } catch (\Throwable) {
            $queueDepth = 0;
        }

        return [
            'queue_depth' => $queueDepth,
            'orders_paid_waiting' => $this->orders->countByStatus(OrderStatus::Paid->value),
            'orders_delivering' => $this->orders->countByStatus(OrderStatus::Delivering->value),
            'orders_delivered' => $this->orders->countByStatus(OrderStatus::Delivered->value),
            'orders_partially_delivered' => $this->orders->countByStatus(OrderStatus::PartiallyDelivered->value),
            'items_delivered' => $this->orderItems->countByStatus(OrderItemStatus::Delivered->value),
            'items_pending' => $this->orderItems->countByStatuses([
                OrderItemStatus::Pending->value,
                OrderItemStatus::Delivering->value,
            ]),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
