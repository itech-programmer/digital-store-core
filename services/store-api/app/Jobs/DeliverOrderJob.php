<?php

namespace App\Jobs;

use App\Contracts\Order\DeliveryServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $orderPublicId,
    ) {
        $this->onQueue('delivery');
    }

    public function handle(DeliveryServiceInterface $delivery): void
    {
        $delivery->deliver($this->orderPublicId);
    }
}
