<?php

namespace App\Services\Supplier;

use App\Contracts\Supplier\SupplierChainInterface;
use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use Illuminate\Support\Facades\Log;

class SupplierChain implements SupplierChainInterface
{
    public function __construct(
        private readonly SupplierClientInterface $primary,
        private readonly SupplierClientInterface $fallback,
        private readonly int $maxRetries,
        private readonly array $backoffMs,
    ) {}

    public function issueWithFallback(string $orderPublicId, string $sku): array
    {
        return $this->issueForPreferred(
            orderPublicId: $orderPublicId,
            sku: $sku,
            requestPrefix: sprintf('req_%s', $orderPublicId),
            preferredSupplier: 'primary',
        );
    }

    public function issueForItem(
        string $orderPublicId,
        string $sku,
        string $orderItemId,
        string $preferredSupplier,
    ): array {
        $itemToken = str_replace('-', '', $orderItemId);

        return $this->issueForPreferred(
            orderPublicId: $orderPublicId,
            sku: $sku,
            requestPrefix: sprintf('req_%s_%s', $orderPublicId, $itemToken),
            preferredSupplier: $preferredSupplier === 'fallback' ? 'fallback' : 'primary',
        );
    }

    public function lookupIssuedCode(string $supplierName, string $requestId): ?string
    {
        $client = $supplierName === 'fallback' ? $this->fallback : $this->primary;

        return $client->findIssuedCode($requestId);
    }

    private function issueForPreferred(
        string $orderPublicId,
        string $sku,
        string $requestPrefix,
        string $preferredSupplier,
    ): array {
        $preferred = $preferredSupplier === 'fallback' ? $this->fallback : $this->primary;
        $other = $preferredSupplier === 'fallback' ? $this->primary : $this->fallback;

        $preferredRequestId = $requestPrefix.'-1';

        $preferredResult = $this->issueWithRetries(
            client: $preferred,
            request: new SupplierIssueRequestDto($preferredRequestId, $sku, $orderPublicId),
        );

        if ($preferredResult->success) {
            return [
                'result' => $preferredResult,
                'supplier' => $preferred->name(),
                'request_id' => $preferredRequestId,
            ];
        }

        $recovered = $preferred->findIssuedCode($preferredRequestId);
        if (is_string($recovered) && $recovered !== '') {
            Log::info('supplier.lie_recovered', [
                'order_id' => $orderPublicId,
                'supplier' => $preferred->name(),
                'request_id' => $preferredRequestId,
            ]);

            return [
                'result' => SupplierIssueResultDto::ok($recovered),
                'supplier' => $preferred->name(),
                'request_id' => $preferredRequestId,
            ];
        }

        $shouldFailover = $preferredResult->timedOut
            || $preferredResult->rateLimited
            || in_array($preferredResult->reason, ['supplier_unavailable', 'out_of_stock', 'timeout'], true);

        if (! $shouldFailover) {
            return [
                'result' => $preferredResult,
                'supplier' => $preferred->name(),
                'request_id' => $preferredRequestId,
            ];
        }

        $otherRequestId = $requestPrefix.'-2';

        Log::info('supplier.fallback', [
            'order_id' => $orderPublicId,
            'from' => $preferred->name(),
            'to' => $other->name(),
            'primary_reason' => $preferredResult->reason,
            'primary_timed_out' => $preferredResult->timedOut,
        ]);

        $otherResult = $this->issueWithRetries(
            client: $other,
            request: new SupplierIssueRequestDto($otherRequestId, $sku, $orderPublicId),
        );

        if ($otherResult->success) {
            return [
                'result' => $otherResult,
                'supplier' => $other->name(),
                'request_id' => $otherRequestId,
            ];
        }

        $recoveredOther = $other->findIssuedCode($otherRequestId);
        if (is_string($recoveredOther) && $recoveredOther !== '') {
            return [
                'result' => SupplierIssueResultDto::ok($recoveredOther),
                'supplier' => $other->name(),
                'request_id' => $otherRequestId,
            ];
        }

        if ($preferredResult->rateLimited || $otherResult->rateLimited) {
            $retryAfter = max(
                $preferredResult->retryAfterSeconds ?? 0,
                $otherResult->retryAfterSeconds ?? 0,
                1
            );

            return [
                'result' => SupplierIssueResultDto::rateLimited($retryAfter),
                'supplier' => $otherResult->rateLimited ? $other->name() : $preferred->name(),
                'request_id' => $otherResult->rateLimited ? $otherRequestId : $preferredRequestId,
            ];
        }

        return [
            'result' => $otherResult,
            'supplier' => $other->name(),
            'request_id' => $otherRequestId,
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

            if ($last->rateLimited || ! $last->timedOut) {
                return $last;
            }
        }

        return $last;
    }
}
