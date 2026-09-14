<?php

namespace App\Repositories;

use App\Contracts\Payment\PaymentWebhookRepositoryInterface;
use App\Models\Payment\PendingWebhook;
use App\Models\Payment\ProcessedWebhookEvent;
use Illuminate\Support\Collection;

class EloquentPaymentWebhookRepository implements PaymentWebhookRepositoryInterface
{
    public function insertProcessedOrIgnore(array $attributes): int
    {
        return ProcessedWebhookEvent::query()->insertOrIgnore($attributes);
    }

    public function updateOrCreatePending(string $eventId, array $attributes): PendingWebhook
    {
        return PendingWebhook::query()->updateOrCreate(
            ['event_id' => $eventId],
            $attributes
        );
    }

    public function listPendingByOrderPublicId(string $orderPublicId): Collection
    {
        return PendingWebhook::query()
            ->where('order_public_id', $orderPublicId)
            ->orderBy('received_at')
            ->get();
    }

    public function deletePending(PendingWebhook $pending): void
    {
        $pending->delete();
    }
}
