<?php

namespace App\Console\Commands;

use App\Contracts\Order\RecoveryServiceInterface;
use Illuminate\Console\Command;

class RecoverStuckOrdersCommand extends Command
{
    protected $signature = 'orders:recover-stuck {--stale-minutes=10}';

    protected $description = 'Safely retry paid/out_of_stock/delivery_failed and stale delivering orders';

    public function handle(RecoveryServiceInterface $recovery): int
    {
        $result = $recovery->recoverStuck((int) $this->option('stale-minutes'));

        $this->info(sprintf(
            'Recovered %d order(s): %s',
            $result['recovered'],
            $result['order_ids'] === [] ? '-' : implode(', ', $result['order_ids'])
        ));

        return self::SUCCESS;
    }
}
