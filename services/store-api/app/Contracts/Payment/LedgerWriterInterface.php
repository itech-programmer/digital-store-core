<?php

namespace App\Contracts\Payment;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;

interface LedgerWriterInterface
{
    public function recordPaymentReceived(Order $order, string $eventId): void;

    public function recordDeliveryCompleted(Order $order, OrderItem $item): void;

    public function recordRefundIssued(Order $order, OrderItem $item): void;
}
