<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $hidden = ['stripe_payload'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'shipping_address' => 'array',
            'stripe_payload' => 'array',
            'total' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}