<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('items');

        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'issued_code' => $this->issued_code,
            'items' => OrderItemResource::collection($this->items),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
            'delivered_at' => optional($this->delivered_at)?->toIso8601String(),
        ];
    }
}
