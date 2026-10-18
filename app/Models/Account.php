<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['user_id', 'name', 'type', 'balance', 'color'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Preložený názov typu (lang/{sk,en}/gros.php → account_types); vlastný typ zostane, ako je. */
    public static function typeLabel(?string $type): ?string
    {
        return __('gros.account_types')[$type] ?? $type;
    }

    /** Pridá type_label do JSON-u (appka zobrazuje typ v jazyku zariadenia). */
    public function withTypeLabel(): static
    {
        return $this->setAttribute('type_label', self::typeLabel($this->type));
    }
}
