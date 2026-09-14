<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'type',
        'price',
        'currency',
        'image_path',
        'is_active',
        'preferred_supplier',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ProductKey::class, 'sku', 'sku');
    }
}
