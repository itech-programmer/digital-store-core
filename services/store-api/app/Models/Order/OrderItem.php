<?php

namespace App\Models\Order;

use App\Enums\OrderItemStatus;
use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'sku',
        'quantity',
        'unit_price',
        'currency',
        'status',
        'issued_code',
        'supplier',
        'version',
        'position',
        'delivered_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderItemStatus::class,
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'version' => 'integer',
            'position' => 'integer',
            'delivered_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function lineAmount(): float
    {
        return (float) $this->unit_price * (int) $this->quantity;
    }

    public function transitionTo(OrderItemStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new \DomainException(sprintf(
                'Invalid order item transition %s -> %s for %s',
                $this->status->value,
                $to->value,
                $this->id
            ));
        }

        $this->status = $to;
        $this->version++;
    }
}
