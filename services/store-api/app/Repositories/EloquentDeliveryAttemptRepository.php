<?php

namespace App\Repositories;

use App\Contracts\Order\DeliveryAttemptRepositoryInterface;
use App\Models\Order\DeliveryAttempt;

class EloquentDeliveryAttemptRepository implements DeliveryAttemptRepositoryInterface
{
    public function save(DeliveryAttempt $attempt): void
    {
        $attempt->save();
    }

    public function findSuccessByOrderItemIdForUpdate(string $orderItemId): ?DeliveryAttempt
    {
        return DeliveryAttempt::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', 'success')
            ->lockForUpdate()
            ->first();
    }

    public function firstOrCreateByRequestId(string $requestId, array $attributes): DeliveryAttempt
    {
        return DeliveryAttempt::query()->firstOrCreate(
            ['request_id' => $requestId],
            $attributes
        );
    }

    public function markPendingAsFallbackUsed(string $orderItemId, string $preferredRequestId): int
    {
        return DeliveryAttempt::query()
            ->where('order_item_id', $orderItemId)
            ->where('request_id', $preferredRequestId)
            ->where('status', 'pending')
            ->update([
                'status' => 'error',
                'error_reason' => 'fallback_used',
                'finished_at' => now(),
            ]);
    }

    public function hasSuccessfulWithCode(string $orderItemId): bool
    {
        return DeliveryAttempt::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', 'success')
            ->whereNotNull('code')
            ->exists();
    }
}
