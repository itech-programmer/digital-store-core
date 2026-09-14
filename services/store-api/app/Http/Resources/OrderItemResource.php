<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'amount' => $this->lineAmount(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'issued_code' => $this->issued_code,
            'supplier' => $this->supplier,
            'delivered_at' => optional($this->delivered_at)?->toIso8601String(),
            'refunded_at' => optional($this->refunded_at)?->toIso8601String(),
        ];
    }
}
