<?php

namespace App\Contracts\Order;

use App\DTO\Order\CreateOrderDto;
use App\Models\Order\Order;

interface OrderServiceInterface
{
    public function create(CreateOrderDto $dto): Order;

    public function findByPublicId(string $publicId): Order;
}
