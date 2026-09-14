<?php

namespace App\DTO\Order;

final readonly class CreateOrderDto
{
    public function __construct(
        public array $items,
        public ?string $publicId = null,
    ) {}
}
