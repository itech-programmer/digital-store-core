<?php

namespace App\DTO\Order;

final readonly class CreateOrderItemDto
{
    public function __construct(
        public string $sku,
        public int $quantity = 1,
    ) {}
}
