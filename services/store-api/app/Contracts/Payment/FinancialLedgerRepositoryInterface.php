<?php

namespace App\Contracts\Payment;

use DateTimeInterface;
use Illuminate\Support\Collection;

interface FinancialLedgerRepositoryInterface
{
    public function insertOrIgnore(array $attributes): int;

    public function sumByEventType(string $eventType): float;

    public function sumByOrderAndEventType(string $orderId, string $eventType): float;

    public function pluckOrderIdsByEventType(string $eventType): Collection;

    public function listByOrderIdUntil(string $orderId, DateTimeInterface $at): Collection;

    public function aggregateByEventTypeInPeriod(DateTimeInterface $from, DateTimeInterface $to): Collection;
}
