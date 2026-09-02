<?php

namespace App\Contracts\Payment;

use App\DTO\Payment\PaymentWebhookDto;

interface PaymentWebhookServiceInterface
{
    public function process(PaymentWebhookDto $dto): void;
}
