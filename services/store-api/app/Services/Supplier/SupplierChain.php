<?php

namespace App\Services\Supplier;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use Illuminate\Support\Facades\Log;

class SupplierChain
{
    public function __construct(
        private readonly SupplierClientInterface $primary,
        private readonly SupplierClientInterface $fallback,
        private readonly int $maxRetries,
        private readonly array $backoffMs,
    ) {}

    public function issueWithFallback(string $orderPublicId, string $sku): array
    {
        $primaryRequestId = sprintf('req_%s-1', $orderPublicId);

        $primaryResult = $this->issueWithRetries(
            client: $this->primary,
            request: new SupplierIssueRequestDto($primaryRequestId, $sku, $orderPublicId),
        );

        if ($primaryResult->success) {
            return [
                'result' => $primaryResult,
                'supplier' => $this->primary->name(),
                'request_id' => $primaryRequestId,
            ];
        }

        $fallbackRequestId = sprintf('req_%s-2', $orderPublicId);

        Log::info('supplier.fallback', [
            'order_id' => $orderPublicId,
            'from' => $this->primary->name(),
            'to' => $this->fallback->name(),
            'primary_reason' => $primaryResult->reason,
            'primary_timed_out' => $primaryResult->timedOut,
        ]);

        $fallbackResult = $this->issueWithRetries(
            client: $this->fallback,
            request: new SupplierIssueRequestDto($fallbackRequestId, $sku, $orderPublicId),
        );

        return [
            'result' => $fallbackResult,
            'supplier' => $this->fallback->name(),
            'request_id' => $fallbackRequestId,
        ];
    }

    private function issueWithRetries(
        SupplierClientInterface $client,
        SupplierIssueRequestDto $request,
    ): SupplierIssueResultDto {
        $attempts = max(1, $this->maxRetries);
        $last = SupplierIssueResultDto::error('not_attempted');

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                $delay = $this->backoffMs[min($i - 1, count($this->backoffMs) - 1)] ?? 0;
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }

            $last = $client->issue($request);

            Log::info('supplier.attempt', [
                'supplier' => $client->name(),
                'request_id' => $request->requestId,
                'try' => $i + 1,
                'success' => $last->success,
                'timed_out' => $last->timedOut,
                'reason' => $last->reason,
            ]);

            if ($last->success) {
                return $last;
            }

            if (! $last->timedOut) {
                return $last;
            }
        }

        return $last;
    }
}
