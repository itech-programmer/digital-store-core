<?php

namespace App\Models\Catalog;

use App\Enums\ProductKeyStatus;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductKey extends Model
{
    protected $fillable = [
        'sku',
        'code',
        'status',
        'order_id',
        'reserved_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductKeyStatus::class,
            'reserved_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
