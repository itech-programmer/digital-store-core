<?php

namespace App\Contracts\Event;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface DomainEventRepositoryInterface
{
    public function create(array $attributes): void;

    public function listForOrderAsOf(string $orderId, array $itemIds, DateTimeInterface $at): Collection;
}
