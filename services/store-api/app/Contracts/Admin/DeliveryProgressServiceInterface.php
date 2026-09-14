<?php

namespace App\Contracts\Admin;

interface DeliveryProgressServiceInterface
{
    public function progress(): array;
}
