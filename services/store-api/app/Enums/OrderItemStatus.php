<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Refunded], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::Delivering], true),
            self::Delivering => in_array($to, [
                self::Delivered,
                self::Failed,
                self::OutOfStock,
                self::DeliveryFailed,
            ], true),
            self::Failed, self::OutOfStock, self::DeliveryFailed => in_array($to, [
                self::Delivering,
                self::Refunded,
            ], true),
            self::Delivered, self::Refunded => false,
        };
    }
}
