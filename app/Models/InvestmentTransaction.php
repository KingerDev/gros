<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentTransaction extends Model
{
    protected $fillable = ['investment_id', 'type', 'units', 'price', 'date', 'note'];

    protected $casts = [
        'units' => 'decimal:8',
        'price' => 'decimal:8',
        'date' => 'date',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}
