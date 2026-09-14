<?php

namespace App\Contracts\Order;

use App\Models\Order\DeliveryAttempt;

interface DeliveryAttemptRepositoryInterface
{
    public function save(DeliveryAttempt $attempt): void;

    public function findSuccessByOrderItemIdForUpdate(string $orderItemId): ?DeliveryAttempt;

    public function firstOrCreateByRequestId(string $requestId, array $attributes): DeliveryAttempt;

    public function markPendingAsFallbackUsed(string $orderItemId, string $preferredRequestId): int;

    public function hasSuccessfulWithCode(string $orderItemId): bool;
}
