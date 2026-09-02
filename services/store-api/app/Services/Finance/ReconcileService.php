<?php

namespace App\Services\Finance;

use App\Contracts\Payment\ReconcileServiceInterface;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Payment\FinancialLedgerEntry;
use Illuminate\Support\Facades\DB;

class ReconcileService implements ReconcileServiceInterface
{
    public function reconcile(): array
    {
        $paidNotDelivered = Order::query()
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Delivering->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('processed_webhook_events')
                    ->whereColumn('processed_webhook_events.order_id', 'orders.id')
                    ->where('processed_webhook_events.status', 'paid');
            })
            ->orderBy('public_id')
            ->pluck('public_id')
            ->all();

        $deliveredNotPaid = Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('processed_webhook_events')
                    ->whereColumn('processed_webhook_events.order_id', 'orders.id')
                    ->where('processed_webhook_events.status', 'paid');
            })
            ->orderBy('public_id')
            ->pluck('public_id')
            ->all();

        $paymentSum = (float) FinancialLedgerEntry::query()
            ->where('event_type', 'payment_received')
            ->sum('amount');

        $deliverySum = (float) FinancialLedgerEntry::query()
            ->where('event_type', 'delivery_completed')
            ->sum('amount');

        $deliveredPaidSum = (float) Order::query()
            ->where('status', OrderStatus::Delivered->value)
            ->sum('amount');

        $ledgerBalanced = abs($deliverySum - $deliveredPaidSum) < 0.001
            && count($deliveredNotPaid) === 0;

        return [
            'paid_not_delivered' => array_values($paidNotDelivered),
            'delivered_not_paid' => array_values($deliveredNotPaid),
            'ledger_balanced' => $ledgerBalanced,
            'ledger_payment_sum' => $paymentSum,
            'ledger_delivery_sum' => $deliverySum,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
