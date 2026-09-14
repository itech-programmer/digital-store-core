<?php

namespace App\Services\Event;

use App\Contracts\Event\DomainEventRecorderInterface;
use App\Contracts\Event\DomainEventRepositoryInterface;
use Illuminate\Support\Str;

class DomainEventRecorder implements DomainEventRecorderInterface
{
    public function __construct(
        private readonly DomainEventRepositoryInterface $events,
    ) {}

    public function record(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload = [],
        ?\DateTimeInterface $at = null,
    ): void {
        $this->events->create([
            'id' => (string) Str::orderedUuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => $at ?? now(),
        ]);
    }
}
