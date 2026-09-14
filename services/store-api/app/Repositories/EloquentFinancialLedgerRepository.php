<?php

namespace App\Repositories;

use App\Contracts\Payment\FinancialLedgerRepositoryInterface;
use App\Models\Payment\FinancialLedgerEntry;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EloquentFinancialLedgerRepository implements FinancialLedgerRepositoryInterface
{
    public function insertOrIgnore(array $attributes): int
    {
        return FinancialLedgerEntry::query()->insertOrIgnore($attributes);
    }

    public function sumByEventType(string $eventType): float
    {
        return (float) FinancialLedgerEntry::query()
            ->where('event_type', $eventType)
            ->sum('amount');
    }

    public function sumByOrderAndEventType(string $orderId, string $eventType): float
    {
        return (float) FinancialLedgerEntry::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->sum('amount');
    }

    public function pluckOrderIdsByEventType(string $eventType): Collection
    {
        return FinancialLedgerEntry::query()
            ->where('event_type', $eventType)
            ->pluck('order_id')
            ->unique();
    }

    public function listByOrderIdUntil(string $orderId, DateTimeInterface $at): Collection
    {
        return FinancialLedgerEntry::query()
            ->where('order_id', $orderId)
            ->where('created_at', '<=', $at)
            ->get();
    }

    public function aggregateByEventTypeInPeriod(DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        return FinancialLedgerEntry::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw('event_type, COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt')
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');
    }
}
