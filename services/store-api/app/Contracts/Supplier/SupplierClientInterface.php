<?php

namespace App\Contracts\Supplier;

use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;

interface SupplierClientInterface
{
    public function name(): string;

    public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto;
}
