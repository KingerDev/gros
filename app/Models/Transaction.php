<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    /**
     * Čistá suma v SQL: výdavok znížený o to, čo sa z neho vrátilo.
     * Pri príjmoch a prevodoch je refunded_amount vždy 0, takže výraz
     * je bezpečné použiť aj tam.
     */
    public const NET_AMOUNT = '(transactions.amount - transactions.refunded_amount)';

    protected $fillable = [
        'user_id', 'account_id', 'to_account_id', 'category_id', 'type', 'amount', 'date', 'note',
        'excluded_from_analytics', 'exclusion_reason', 'source', 'source_id', 'refund_for_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'date' => 'date',
        'excluded_from_analytics' => 'boolean',
    ];

    protected $appends = ['net_amount'];

    /**
     * Len transakcie, ktoré vstupujú do analýz a rozpočtov.
     * Vylúčené (napr. preplatené firmou, omylom zdvojené) sa stále premietajú
     * do zostatkov účtov — len nekazia štatistiky. Vrátenia peňazí sa nerátajú
     * ako príjem: ich suma je už odpočítaná z pôvodného výdavku.
     */
    public function scopeAnalyzed(Builder $query): Builder
    {
        return $query->where('excluded_from_analytics', false)->whereNull('refund_for_id');
    }

    /**
     * SQL súčet čistých súm, napr.:
     *   ->selectRaw('category_id, '.Transaction::netSum('amount'))
     */
    public static function netSum(string $alias = 'amount'): string
    {
        return 'SUM'.self::NET_AMOUNT.' as '.$alias;
    }

    /** Čistá suma ako výraz — na orderBy/where. */
    public static function netExpression(): Expression
    {
        return DB::raw(self::NET_AMOUNT);
    }

    /**
     * Mesiac ako 'YYYY-MM' pre GROUP BY. Produkcia beží na MySQL, testy na
     * SQLite — každá má na to vlastnú funkciu.
     */
    public static function yearMonth(string $column = 'date'): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /** Suma po odrátaní vrátení — to, čo ma nákup reálne stál. */
    public function getNetAmountAttribute(): float
    {
        return round((float) $this->amount - (float) ($this->attributes['refunded_amount'] ?? 0), 2);
    }

    public function isRefund(): bool
    {
        return $this->refund_for_id !== null;
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

    /** Pôvodný výdavok, ku ktorému je toto vrátenie spárované. */
    public function refundFor(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_for_id');
    }

    /** Vrátenia spárované s týmto výdavkom. */
    public function refunds(): HasMany
    {
        return $this->hasMany(Transaction::class, 'refund_for_id')->orderBy('date')->orderBy('id');
    }
}
