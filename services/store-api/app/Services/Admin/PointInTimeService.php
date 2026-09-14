<?php

namespace App\Services\Admin;

use App\Contracts\Admin\PointInTimeServiceInterface;
use App\Contracts\Event\DomainEventRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Payment\FinancialLedgerRepositoryInterface;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PointInTimeService implements PointInTimeServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly DomainEventRepositoryInterface $domainEvents,
        private readonly FinancialLedgerRepositoryInterface $ledger,
    ) {}

    public function orderAsOf(string $orderPublicId, Carbon $at): array
    {
        $order = $this->orders->findByPublicId($orderPublicId);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderPublicId]);
        }

        $itemIds = $order->items->pluck('id')->all();
        $events = $this->domainEvents->listForOrderAsOf($order->id, $itemIds, $at);

        $state = [
            'id' => $order->public_id,
            'status' => OrderStatus::Created->value,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'issued_code' => null,
            'items' => [],
        ];

        foreach ($events as $event) {
            $payload = $event->payload ?? [];

            if ($event->aggregate_type === 'order') {
                if ($event->event_type === 'order.created') {
                    $state['status'] = OrderStatus::Created->value;
                    foreach (($payload['items'] ?? []) as $item) {
                        $state['items'][$item['id']] = [
                            'id' => $item['id'],
                            'sku' => $item['sku'],
                            'status' => OrderItemStatus::Pending->value,
                            'issued_code' => null,
                            'unit_price' => $item['unit_price'] ?? null,
                        ];
                    }
                }

                if ($event->event_type === 'order.status_changed') {
                    $state['status'] = $payload['status'] ?? $state['status'];
                    if (! empty($payload['issued_code'])) {
                        $state['issued_code'] = $payload['issued_code'];
                    }
                }
            }

            if ($event->aggregate_type === 'order_item') {
                $itemId = $event->aggregate_id;
                if (! isset($state['items'][$itemId])) {
                    $state['items'][$itemId] = [
                        'id' => $itemId,
                        'sku' => $payload['sku'] ?? null,
                        'status' => OrderItemStatus::Pending->value,
                        'issued_code' => null,
                        'unit_price' => $payload['unit_price'] ?? null,
                    ];
                }

                if ($event->event_type === 'order_item.status_changed') {
                    $state['items'][$itemId]['status'] = $payload['status'] ?? $state['items'][$itemId]['status'];
                }

                if ($event->event_type === 'order_item.delivered') {
                    $state['items'][$itemId]['status'] = OrderItemStatus::Delivered->value;
                    $state['items'][$itemId]['issued_code'] = $payload['issued_code'] ?? null;
                    $state['items'][$itemId]['sku'] = $payload['sku'] ?? $state['items'][$itemId]['sku'];
                }

                if ($event->event_type === 'order_item.refunded') {
                    $state['items'][$itemId]['status'] = OrderItemStatus::Refunded->value;
                    $state['items'][$itemId]['issued_code'] = null;
                }
            }
        }

        $ledger = $this->ledger->listByOrderIdUntil($order->id, $at);

        $money = [
            'payment_received' => (float) $ledger->where('event_type', 'payment_received')->sum('amount'),
            'delivery_completed' => (float) $ledger->where('event_type', 'delivery_completed')->sum('amount'),
            'refund_issued' => (float) $ledger->where('event_type', 'refund_issued')->sum('amount'),
        ];
        $money['balanced'] = abs($money['payment_received'] - ($money['delivery_completed'] + $money['refund_issued'])) < 0.001
            || $money['payment_received'] === 0.0;

        return [
            'as_of' => $at->toIso8601String(),
            'order' => [
                'id' => $state['id'],
                'status' => $state['status'],
                'amount' => $state['amount'],
                'currency' => $state['currency'],
                'issued_code' => $state['issued_code'],
                'items' => array_values($state['items']),
            ],
            'money' => $money,
            'events_applied' => $events->count(),
        ];
    }

    public function financePeriod(Carbon $from, Carbon $to): array
    {
        $rows = $this->ledger->aggregateByEventTypeInPeriod($from, $to);

        $payment = (float) ($rows->get('payment_received')?->total ?? 0);
        $delivery = (float) ($rows->get('delivery_completed')?->total ?? 0);
        $refund = (float) ($rows->get('refund_issued')?->total ?? 0);

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'payment_received' => $payment,
            'delivery_completed' => $delivery,
            'refund_issued' => $refund,
            'net' => $payment - $delivery - $refund,
            'counts' => [
                'payment_received' => (int) ($rows->get('payment_received')?->cnt ?? 0),
                'delivery_completed' => (int) ($rows->get('delivery_completed')?->cnt ?? 0),
                'refund_issued' => (int) ($rows->get('refund_issued')?->cnt ?? 0),
            ],
        ];
    }
}
