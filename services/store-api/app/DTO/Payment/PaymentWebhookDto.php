<?php

namespace App\DTO\Payment;

use App\Enums\PaymentWebhookStatus;
use DateTimeImmutable;

final readonly class PaymentWebhookDto
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public PaymentWebhookStatus $status,
        public int $amount,
        public string $currency,
        public DateTimeImmutable $createdAt,
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            eventId: $data['event_id'],
            orderId: $data['order_id'],
            status: PaymentWebhookStatus::from($data['status']),
            amount: (int) $data['amount'],
            currency: $data['currency'],
            createdAt: new DateTimeImmutable($data['created_at']),
        );
    }
}
