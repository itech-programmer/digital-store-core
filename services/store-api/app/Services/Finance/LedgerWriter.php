<?php

namespace App\Services\Finance;

use App\Contracts\Payment\LedgerWriterInterface;
use App\Models\Order\Order;
use App\Models\Payment\FinancialLedgerEntry;
use Illuminate\Support\Facades\Log;

class LedgerWriter implements LedgerWriterInterface
{
    public function recordPaymentReceived(Order $order, string $eventId): void
    {
        $this->insertIgnore(
            orderId: $order->id,
            eventType: 'payment_received',
            amount: (float) $order->amount,
            currency: $order->currency,
            referenceId: 'pay_'.$eventId,
        );
    }

    public function recordDeliveryCompleted(Order $order): void
    {
        $this->insertIgnore(
            orderId: $order->id,
            eventType: 'delivery_completed',
            amount: (float) $order->amount,
            currency: $order->currency,
            referenceId: 'del_'.$order->public_id,
        );
    }

    private function insertIgnore(
        string $orderId,
        string $eventType,
        float $amount,
        string $currency,
        string $referenceId,
    ): void {
        $inserted = FinancialLedgerEntry::query()->insertOrIgnore([
            'order_id' => $orderId,
            'event_type' => $eventType,
            'amount' => $amount,
            'currency' => $currency,
            'reference_id' => $referenceId,
            'created_at' => now(),
        ]);

        if ($inserted > 0) {
            Log::info('ledger.recorded', [
                'event_type' => $eventType,
                'reference_id' => $referenceId,
                'order_id' => $orderId,
            ]);
        }
    }
}
