<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::PaymentFailed], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Created => in_array($to, [self::Paid, self::PaymentFailed], true),
            self::Paid => $to === self::Delivering,
            self::Delivering => in_array($to, [self::Delivered, self::OutOfStock, self::DeliveryFailed], true),
            self::OutOfStock, self::DeliveryFailed => $to === self::Delivering,
            self::Delivered, self::PaymentFailed => false,
        };
    }
}
