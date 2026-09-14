<?php

namespace App\Services\Supplier;

use App\Contracts\Supplier\SupplierIssuanceRepositoryInterface;
use App\Contracts\Supplier\SupplierIssuanceServiceInterface;
use App\Models\Order\OrderItem;
use App\Models\Supplier\SupplierIssuance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class SupplierIssuanceService implements SupplierIssuanceServiceInterface
{
    public function __construct(
        private readonly SupplierIssuanceRepositoryInterface $issuances,
    ) {}

    public function findByRequestId(string $requestId): ?SupplierIssuance
    {
        return $this->issuances->findByRequestId($requestId);
    }

    public function isAcceptableCode(string $code, ?string $expectedSku = null, ?string $responseSku = null): bool
    {
        if ($code === '' || str_starts_with(strtoupper($code), 'WRONG-')) {
            return false;
        }

        if ($expectedSku !== null && $responseSku !== null && $responseSku !== '' && $responseSku !== $expectedSku) {
            return false;
        }

        return true;
    }

    public function registerIssued(
        string $requestId,
        string $supplier,
        OrderItem $item,
        string $code,
        ?array $rawResponse = null,
        ?string $responseSku = null,
    ): array {
        $existing = $this->findByRequestId($requestId);
        if ($existing !== null) {
            if ($existing->order_item_id === $item->id && $existing->status === 'issued' && $existing->code) {
                return ['ok' => true, 'issuance' => $existing, 'reason' => null];
            }

            return ['ok' => false, 'issuance' => $existing, 'reason' => 'request_id_conflict'];
        }

        if (! $this->isAcceptableCode($code, $item->sku, $responseSku)) {
            $this->issuances->create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'supplier' => $supplier,
                'order_item_id' => $item->id,
                'sku' => $item->sku,
                'code' => null,
                'status' => 'rejected_wrong_code',
                'raw_response' => $rawResponse ?? ['code' => $code, 'sku' => $responseSku],
                'created_at' => now(),
            ]);

            return ['ok' => false, 'issuance' => null, 'reason' => 'wrong_code'];
        }

        $duplicate = $this->issuances->findIssuedByCode($code);

        if ($duplicate !== null) {
            $this->issuances->create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'supplier' => $supplier,
                'order_item_id' => $item->id,
                'sku' => $item->sku,
                'code' => null,
                'status' => 'rejected_duplicate',
                'raw_response' => $rawResponse ?? ['code' => $code, 'duplicate_of' => $duplicate->id],
                'created_at' => now(),
            ]);

            return ['ok' => false, 'issuance' => $duplicate, 'reason' => 'duplicate_code'];
        }

        try {
            $issuance = $this->issuances->create([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'supplier' => $supplier,
                'order_item_id' => $item->id,
                'sku' => $item->sku,
                'code' => $code,
                'status' => 'issued',
                'raw_response' => $rawResponse,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            $existing = $this->findByRequestId($requestId);
            if ($existing?->code && $existing->order_item_id === $item->id) {
                return ['ok' => true, 'issuance' => $existing, 'reason' => null];
            }

            return ['ok' => false, 'issuance' => null, 'reason' => 'duplicate_code'];
        }

        return ['ok' => true, 'issuance' => $issuance, 'reason' => null];
    }
}
