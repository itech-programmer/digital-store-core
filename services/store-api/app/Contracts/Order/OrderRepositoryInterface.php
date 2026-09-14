<?php

namespace App\Contracts\Order;

use App\Models\Order\Order;
use Illuminate\Support\Collection;

interface OrderRepositoryInterface
{
    public function findByPublicId(string $publicId): ?Order;

    public function findByPublicIdForUpdate(string $publicId): ?Order;

    public function findByIdForUpdate(string $id): ?Order;

    public function create(array $attributes): Order;

    public function save(Order $order): void;

    public function loadItemsOrdered(Order $order): Order;

    public function freshWithItems(Order $order): Order;

    public function fresh(Order $order): ?Order;

    public function claimForDelivery(string $orderId, array $fromStatuses): int;

    public function countByStatus(string $status): int;

    public function findPublicIdById(string $id): ?string;

    public function listStaleDelivering(int $staleMinutes): Collection;

    public function listRecoveryCandidates(): Collection;

    public function listPaidNotDeliveredPublicIds(): array;

    public function listDeliveredNotPaidPublicIds(): array;
}
