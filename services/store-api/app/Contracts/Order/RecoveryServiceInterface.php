<?php

namespace App\Contracts\Order;

interface RecoveryServiceInterface
{
    public function recoverStuck(int $staleMinutes = 10): array;
}
