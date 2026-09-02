<?php

namespace App\Services\Order;

use App\Contracts\Order\DeliveryServiceInterface;
use App\Contracts\Order\RecoveryServiceInterface;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use Illuminate\Support\Facades\Log;

class RecoveryService implements RecoveryServiceInterface
{
    public function __construct(
        private readonly DeliveryServiceInterface $delivery,
    ) {}

    public function recoverStuck(int $staleMinutes = 10): array
    {
        $recovered = [];

        Order::query()
            ->where('status', OrderStatus::Delivering->value)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->orderBy('id')
            ->each(function (Order $order) {
                $order->status = OrderStatus::Paid;
                $order->version++;
                $order->save();

                Log::warning('recovery.reset_stale_delivering', [
                    'order_id' => $order->public_id,
                ]);
            });

        $candidates = Order::query()
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $order) {
            Log::info('recovery.attempt', ['order_id' => $order->public_id, 'status' => $order->status->value]);

            $this->delivery->deliver($order->public_id);

            $fresh = $order->fresh();
            if ($fresh?->status === OrderStatus::Delivered) {
                $recovered[] = $fresh->public_id;
            }
        }

        return [
            'recovered' => count($recovered),
            'order_ids' => $recovered,
        ];
    }
}
