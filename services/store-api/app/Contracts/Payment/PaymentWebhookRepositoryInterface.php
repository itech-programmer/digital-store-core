<?php

namespace App\Contracts\Payment;

use App\Models\Payment\PendingWebhook;
use Illuminate\Support\Collection;

interface PaymentWebhookRepositoryInterface
{
    public function insertProcessedOrIgnore(array $attributes): int;

    public function updateOrCreatePending(string $eventId, array $attributes): PendingWebhook;

    public function listPendingByOrderPublicId(string $orderPublicId): Collection;

    public function deletePending(PendingWebhook $pending): void;
}
