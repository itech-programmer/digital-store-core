<?php

namespace App\Contracts\Event;

interface DomainEventRecorderInterface
{
    public function record(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload = [],
        ?\DateTimeInterface $at = null,
    ): void;
}
