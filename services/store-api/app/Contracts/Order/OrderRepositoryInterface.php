<?php

namespace App\Contracts\Order;

use App\Models\Order\Order;

interface OrderRepositoryInterface
{
    public function findByPublicId(string $publicId): ?Order;

    public function findByPublicIdForUpdate(string $publicId): ?Order;

    public function save(Order $order): void;
}
