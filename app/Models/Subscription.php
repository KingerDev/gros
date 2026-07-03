<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['user_id', 'name', 'amount', 'cycle', 'next_payment', 'color'];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_payment' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMonthlyAmountAttribute(): float
    {
        return $this->cycle === 'yearly' ? (float) $this->amount / 12 : (float) $this->amount;
    }
}
