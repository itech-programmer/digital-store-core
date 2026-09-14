<?php

namespace App\Jobs;

use App\Contracts\Order\DeliveryServiceInterface;
use App\Exceptions\SupplierRateLimitedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeliverOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 100;

    public function __construct(
        public readonly string $orderPublicId,
    ) {
        $this->onQueue('delivery');
    }

    public function handle(DeliveryServiceInterface $delivery): void
    {
        try {
            $delivery->deliver($this->orderPublicId);
        } catch (SupplierRateLimitedException $e) {
            Log::warning('delivery.rate_limited_release', [
                'order_id' => $this->orderPublicId,
                'retry_after' => $e->retryAfterSeconds,
            ]);

            $this->release(max(1, $e->retryAfterSeconds));
        }
    }
}
