<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investment extends Model
{
    protected $fillable = ['user_id', 'ticker', 'name', 'kind', 'quote_symbol', 'quote_source', 'units', 'buy_price', 'current_price', 'last_price_at', 'color'];

    protected $casts = [
        'units' => 'decimal:8',
        'buy_price' => 'decimal:8',
        'current_price' => 'decimal:8',
        'last_price_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class)->orderBy('date')->orderBy('id');
    }

    public function getValueAttribute(): float
    {
        return (float) $this->units * (float) $this->current_price;
    }

    public function getCostAttribute(): float
    {
        return (float) $this->units * (float) $this->buy_price;
    }

    public function getGainAttribute(): float
    {
        return $this->value - $this->cost;
    }

    /** Realizovaný zisk z predajov (average-cost metóda). */
    public function getRealizedGainAttribute(): float
    {
        return $this->computeAggregates()['realized'];
    }

    /**
     * Prepočíta pozíciu z jednotlivých nákupov/predajov metódou váženého
     * priemeru (average cost) a uloží units + buy_price.
     */
    public function recomputeFromLots(): void
    {
        $agg = $this->computeAggregates();
        $this->units = round($agg['units'], 8);
        $this->buy_price = round($agg['avg'], 8);
        $this->save();
    }

    /** @return array{units: float, avg: float, realized: float} */
    public function computeAggregates(): array
    {
        $units = 0.0;
        $avg = 0.0;
        $realized = 0.0;

        foreach ($this->lots()->get() as $lot) {
            $u = (float) $lot->units;
            $p = (float) $lot->price;

            if ($lot->type === 'buy') {
                $newUnits = $units + $u;
                $avg = $newUnits > 0 ? ($units * $avg + $u * $p) / $newUnits : 0;
                $units = $newUnits;
            } else { // sell
                $sell = min($u, $units);
                $realized += ($p - $avg) * $sell;
                $units -= $sell;
                if ($units <= 1e-9) {
                    $units = 0.0;
                }
            }
        }

        return ['units' => $units, 'avg' => $avg, 'realized' => $realized];
    }
}
