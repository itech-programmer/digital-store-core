<?php

namespace App\Models\Payment;

use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'financial_ledger';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'event_type',
        'amount',
        'currency',
        'reference_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
