<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;

class PendingWebhook extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'order_public_id',
        'status',
        'amount',
        'currency',
        'payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }
}
