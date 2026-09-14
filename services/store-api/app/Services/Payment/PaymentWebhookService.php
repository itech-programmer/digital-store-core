<?php

namespace App\Services\Payment;

use App\Contracts\Event\DomainEventRecorderInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Payment\LedgerWriterInterface;
use App\Contracts\Payment\PaymentWebhookRepositoryInterface;
use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\DTO\Payment\PaymentWebhookDto;
use App\Enums\OrderStatus;
use App\Enums\PaymentWebhookStatus;
use App\Jobs\DeliverOrderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService implements PaymentWebhookServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly PaymentWebhookRepositoryInterface $webhooks,
        private readonly LedgerWriterInterface $ledger,
        private readonly DomainEventRecorderInterface $events,
    ) {}

    public function process(PaymentWebhookDto $dto): void
    {
        $orderPublicIdToDeliver = null;

        DB::transaction(function () use ($dto, &$orderPublicIdToDeliver) {
            $order = $this->orders->findByPublicIdForUpdate($dto->orderId);

            if ($order === null) {
                $this->storePending($dto);

                return;
            }

            $inserted = $this->webhooks->insertProcessedOrIgnore([
                'event_id' => $dto->eventId,
                'order_id' => $order->id,
                'status' => $dto->status->value,
                'amount' => $dto->amount,
                'currency' => $dto->currency,
                'payload' => json_encode([
                    'event_id' => $dto->eventId,
                    'order_id' => $dto->orderId,
                    'status' => $dto->status->value,
                    'amount' => $dto->amount,
                    'currency' => $dto->currency,
                    'created_at' => $dto->createdAt->format('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR),
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                Log::info('payment.webhook.duplicate', [
                    'event_id' => $dto->eventId,
                    'order_id' => $dto->orderId,
                ]);

                return;
            }

            if ($order->status !== OrderStatus::Created) {
                Log::info('payment.webhook.ignored_status', [
                    'event_id' => $dto->eventId,
                    'order_id' => $order->public_id,
                    'status' => $order->status->value,
                ]);

                return;
            }

            if ($dto->status === PaymentWebhookStatus::Failed) {
                $order->transitionTo(OrderStatus::PaymentFailed);
                $this->orders->save($order);
                $this->events->record('order', $order->id, 'order.status_changed', [
                    'status' => OrderStatus::PaymentFailed->value,
                ]);

                return;
            }

            $order->transitionTo(OrderStatus::Paid);
            $order->paid_at = now();
            $this->orders->save($order);

            $this->ledger->recordPaymentReceived($order, $dto->eventId);
            $this->events->record('order', $order->id, 'order.status_changed', [
                'status' => OrderStatus::Paid->value,
            ]);

            Log::info('payment.webhook.paid', [
                'event_id' => $dto->eventId,
                'order_id' => $order->public_id,
            ]);

            $orderPublicIdToDeliver = $order->public_id;
        });

        if ($orderPublicIdToDeliver !== null) {
            DeliverOrderJob::dispatch($orderPublicIdToDeliver);
        }
    }

    private function storePending(PaymentWebhookDto $dto): void
    {
        $this->webhooks->updateOrCreatePending($dto->eventId, [
            'order_public_id' => $dto->orderId,
            'status' => $dto->status->value,
            'amount' => $dto->amount,
            'currency' => $dto->currency,
            'payload' => [
                'event_id' => $dto->eventId,
                'order_id' => $dto->orderId,
                'status' => $dto->status->value,
                'amount' => $dto->amount,
                'currency' => $dto->currency,
                'created_at' => $dto->createdAt->format('Y-m-d\TH:i:s\Z'),
            ],
            'received_at' => now(),
        ]);

        Log::info('payment.webhook.pending', [
            'event_id' => $dto->eventId,
            'order_id' => $dto->orderId,
        ]);
    }
}
