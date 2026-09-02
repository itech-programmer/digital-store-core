<?php

use App\Jobs\RecoverStuckOrdersJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new RecoverStuckOrdersJob)->everyFiveMinutes();
