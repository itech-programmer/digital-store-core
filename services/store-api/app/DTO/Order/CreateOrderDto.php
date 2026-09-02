<?php

namespace App\DTO\Order;

final readonly class CreateOrderDto
{
    public function __construct(
        public string $sku,
        public ?string $publicId = null,
    ) {}
}
