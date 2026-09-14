<?php

namespace App\Services\Supplier;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class HttpSupplierClient implements SupplierClientInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $clientRateLimitPerMinute = 0,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
    {
        if ($this->clientRateLimitPerMinute > 0) {
            $limiterKey = 'supplier-issue:'.$this->name;
            if (RateLimiter::tooManyAttempts($limiterKey, $this->clientRateLimitPerMinute)) {
                return SupplierIssueResultDto::rateLimited(RateLimiter::availableIn($limiterKey) ?: 60);
            }
        }

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post('/issue', [
                    'request_id' => $request->requestId,
                    'sku' => $request->sku,
                    'order_id' => $request->orderId,
                ]);
        } catch (ConnectionException) {
            return SupplierIssueResultDto::timeout();
        }

        if ($this->clientRateLimitPerMinute > 0 && $response->status() !== 429) {
            RateLimiter::hit('supplier-issue:'.$this->name, 60);
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: $response->json('retry_after') ?: 60);

            return SupplierIssueResultDto::rateLimited($retryAfter > 0 ? $retryAfter : 60);
        }

        if ($response->successful()) {
            $code = $response->json('code');

            if (is_string($code) && $code !== '') {
                $responseSku = $response->json('sku');

                return SupplierIssueResultDto::ok(
                    $code,
                    is_string($responseSku) && $responseSku !== '' ? $responseSku : null,
                );
            }

            return SupplierIssueResultDto::error('invalid_response');
        }

        $reason = $response->json('reason');

        return SupplierIssueResultDto::error(
            is_string($reason) && $reason !== '' ? $reason : 'supplier_error'
        );
    }

    public function findIssuedCode(string $requestId): ?string
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->get('/issuances/'.rawurlencode($requestId));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $code = $response->json('code');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
