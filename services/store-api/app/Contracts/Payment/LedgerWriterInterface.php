<?php

namespace App\Contracts\Payment;

use App\Models\Order\Order;

interface LedgerWriterInterface
{
    public function recordPaymentReceived(Order $order, string $eventId): void;

    public function recordDeliveryCompleted(Order $order): void;
}
