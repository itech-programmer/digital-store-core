<?php

namespace App\Contracts\Admin;

use Carbon\Carbon;

interface PointInTimeServiceInterface
{
    public function orderAsOf(string $orderPublicId, Carbon $at): array;

    public function financePeriod(Carbon $from, Carbon $to): array;
}
