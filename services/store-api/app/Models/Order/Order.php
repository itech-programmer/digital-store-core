<?php

namespace App\Models\Order;

use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'public_id',
        'sku',
        'amount',
        'currency',
        'status',
        'issued_code',
        'version',
        'paid_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function transitionTo(OrderStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new \DomainException(sprintf(
                'Invalid order transition %s -> %s for %s',
                $this->status->value,
                $to->value,
                $this->public_id
            ));
        }

        $this->status = $to;
        $this->version++;
    }
}
