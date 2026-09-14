<?php

namespace App\Services\Finance;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Payment\FinancialLedgerRepositoryInterface;
use App\Contracts\Payment\ReconcileServiceInterface;

class ReconcileService implements ReconcileServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly FinancialLedgerRepositoryInterface $ledger,
    ) {}

    public function reconcile(): array
    {
        $paidNotDelivered = $this->orders->listPaidNotDeliveredPublicIds();
        $deliveredNotPaid = $this->orders->listDeliveredNotPaidPublicIds();

        $paymentSum = $this->ledger->sumByEventType('payment_received');
        $deliverySum = $this->ledger->sumByEventType('delivery_completed');
        $refundSum = $this->ledger->sumByEventType('refund_issued');

        $unbalancedOrders = [];
        $paidOrderIds = $this->ledger->pluckOrderIdsByEventType('payment_received');

        foreach ($paidOrderIds as $orderId) {
            $paid = $this->ledger->sumByOrderAndEventType($orderId, 'payment_received');
            $delivered = $this->ledger->sumByOrderAndEventType($orderId, 'delivery_completed');
            $refunded = $this->ledger->sumByOrderAndEventType($orderId, 'refund_issued');

            if (abs($paid - ($delivered + $refunded)) >= 0.001) {
                $publicId = $this->orders->findPublicIdById($orderId);
                if ($publicId) {
                    $unbalancedOrders[] = $publicId;
                }
            }
        }

        $ledgerBalanced = abs($paymentSum - ($deliverySum + $refundSum)) < 0.001
            && count($deliveredNotPaid) === 0
            && count($unbalancedOrders) === 0;

        return [
            'paid_not_delivered' => array_values($paidNotDelivered),
            'delivered_not_paid' => array_values($deliveredNotPaid),
            'unbalanced_orders' => array_values($unbalancedOrders),
            'ledger_balanced' => $ledgerBalanced,
            'ledger_payment_sum' => $paymentSum,
            'ledger_delivery_sum' => $deliverySum,
            'ledger_refund_sum' => $refundSum,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
