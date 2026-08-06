<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'account_id', 'to_account_id', 'category_id', 'type', 'amount', 'date', 'note',
        'excluded_from_analytics', 'exclusion_reason', 'source', 'source_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'excluded_from_analytics' => 'boolean',
    ];

    /**
     * Len transakcie, ktoré vstupujú do analýz a rozpočtov.
     * Vylúčené (napr. preplatené firmou, omylom zdvojené) sa stále premietajú
     * do zostatkov účtov — len nekazia štatistiky.
     */
    public function scopeAnalyzed(Builder $query): Builder
    {
        return $query->where('excluded_from_analytics', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
