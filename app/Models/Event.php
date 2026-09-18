<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Udalosť (výlet, svadba, sťahovanie) — skupina výdavkov naprieč
 * kategóriami. Jej výdavky sú skutočné, ale nie bežné: rátajú sa do toho,
 * koľko si minul, no nie do toho, koľko zvyčajne míňaš.
 */
class Event extends Model
{
    protected $fillable = ['user_id', 'name', 'starts_on', 'ends_on', 'budget', 'color', 'icon', 'note'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'budget' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Počet dní vrátane prvého aj posledného. */
    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }
}
