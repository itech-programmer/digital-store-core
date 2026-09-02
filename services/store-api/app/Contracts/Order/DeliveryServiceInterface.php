<?php

namespace App\Contracts\Order;

interface DeliveryServiceInterface
{
    public function deliver(string $orderPublicId): void;
}
