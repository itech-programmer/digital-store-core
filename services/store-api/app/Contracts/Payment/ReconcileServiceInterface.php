<?php

namespace App\Contracts\Payment;

interface ReconcileServiceInterface
{
    public function reconcile(): array;
}
