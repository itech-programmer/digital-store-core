<?php

namespace App\Services\Finance;

use App\Contracts\Payment\FinancialLedgerRepositoryInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use Illuminate\Support\Facades\Log;

class LedgerWriter implements LedgerWriterInterface
{
    public function __construct(
        private readonly FinancialLedgerRepositoryInterface $ledger,
    ) {}

    public function recordPaymentReceived(Order $order, string $eventId): void
    {
        $this->insertIgnore(
            orderId: $order->id,
            orderItemId: null,
            eventType: 'payment_received',
            amount: (float) $order->amount,
            currency: $order->currency,
            referenceId: 'pay_'.$eventId,
        );
    }

    public function recordDeliveryCompleted(Order $order, OrderItem $item): void
    {
        $this->insertIgnore(
            orderId: $order->id,
            orderItemId: $item->id,
            eventType: 'delivery_completed',
            amount: $item->lineAmount(),
            currency: $item->currency,
            referenceId: 'del_'.$item->id,
        );
    }

    public function recordRefundIssued(Order $order, OrderItem $item): void
    {
        $this->insertIgnore(
            orderId: $order->id,
            orderItemId: $item->id,
            eventType: 'refund_issued',
            amount: $item->lineAmount(),
            currency: $item->currency,
            referenceId: 'ref_'.$item->id,
        );
    }

    private function insertIgnore(
        string $orderId,
        ?string $orderItemId,
        string $eventType,
        float $amount,
        string $currency,
        string $referenceId,
    ): void {
        $inserted = $this->ledger->insertOrIgnore([
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
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
                'order_item_id' => $orderItemId,
            ]);
        }
    }
}
