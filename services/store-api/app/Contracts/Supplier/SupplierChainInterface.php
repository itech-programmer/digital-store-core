<?php

namespace App\Contracts\Supplier;

interface SupplierChainInterface
{
    public function issueWithFallback(string $orderPublicId, string $sku): array;

    public function issueForItem(
        string $orderPublicId,
        string $sku,
        string $orderItemId,
        string $preferredSupplier,
    ): array;

    public function lookupIssuedCode(string $supplierName, string $requestId): ?string;
}
