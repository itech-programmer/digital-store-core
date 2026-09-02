<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockCache extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'sku';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'product_stock_cache';

    protected $fillable = [
        'sku',
        'available_count',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'available_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }
}
