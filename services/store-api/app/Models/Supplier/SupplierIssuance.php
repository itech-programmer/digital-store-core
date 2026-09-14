<?php

namespace App\Models\Supplier;

use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierIssuance extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'supplier',
        'order_item_id',
        'sku',
        'code',
        'status',
        'raw_response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
