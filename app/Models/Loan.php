<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    protected $fillable = ['user_id', 'kind', 'name', 'balance', 'principal', 'payment', 'rate', 'next_payment', 'color'];

    protected $casts = [
        'balance' => 'decimal:2',
        'principal' => 'decimal:2',
        'payment' => 'decimal:2',
        'rate' => 'decimal:2',
        'next_payment' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
