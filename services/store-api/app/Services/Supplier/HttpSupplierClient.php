<?php

namespace App\Services\Supplier;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpSupplierClient implements SupplierClientInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
    {
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

        if ($response->successful()) {
            $code = $response->json('code');

            if (is_string($code) && $code !== '') {
                return SupplierIssueResultDto::ok($code);
            }

            return SupplierIssueResultDto::error('invalid_response');
        }

        $reason = $response->json('reason');

        return SupplierIssueResultDto::error(
            is_string($reason) && $reason !== '' ? $reason : 'supplier_error'
        );
    }
}
