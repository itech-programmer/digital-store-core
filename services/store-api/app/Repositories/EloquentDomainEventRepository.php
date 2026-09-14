<?php

namespace App\Repositories;

use App\Contracts\Event\DomainEventRepositoryInterface;
use App\Models\Event\DomainEvent;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EloquentDomainEventRepository implements DomainEventRepositoryInterface
{
    public function create(array $attributes): void
    {
        DomainEvent::query()->create($attributes);
    }

    public function listForOrderAsOf(string $orderId, array $itemIds, DateTimeInterface $at): Collection
    {
        return DomainEvent::query()
            ->where(function ($q) use ($orderId, $itemIds) {
                $q->where(function ($inner) use ($orderId) {
                    $inner->where('aggregate_type', 'order')
                        ->where('aggregate_id', $orderId);
                })->orWhere(function ($inner) use ($itemIds) {
                    $inner->where('aggregate_type', 'order_item')
                        ->whereIn('aggregate_id', $itemIds);
                });
            })
            ->where('created_at', '<=', $at)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
