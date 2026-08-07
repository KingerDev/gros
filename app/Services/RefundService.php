<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vrátenie tovaru: peniaze, ktoré prišli späť z už zaplateného výdavku.
 *
 * Vrátenie je normálna príjmová transakcia (peniaze naozaj pristáli na účte),
 * navyše spárovaná s pôvodným výdavkom cez refund_for_id. Do analýz nevstupuje
 * ako príjem — namiesto toho sa jej suma odpočíta z pôvodného výdavku, takže
 * nákup za 300 € s vrátením 200 € sa v štatistikách javí ako 100 €.
 * Zníženie sa prejaví v mesiaci a kategórii PÔVODNÉHO nákupu, aj keď peniaze
 * prišli neskôr — ide o to, koľko ten nákup naozaj stál.
 */
class RefundService
{
    /** Vytvorí nové vrátenie k výdavku a pripíše peniaze na účet. */
    public function create(Transaction $original, array $data): Transaction
    {
        $this->assertRefundable($original);
        $this->assertRefundFits($original, (float) $data['amount']);

        return DB::transaction(function () use ($original, $data) {
            $refund = $original->user->transactions()->create([
                'account_id' => $data['account_id'],
                'category_id' => null,
                'type' => 'income',
                'amount' => $data['amount'],
                'date' => $data['date'],
                'note' => $data['note'] ?? null,
                'refund_for_id' => $original->id,
            ]);

            Account::whereKey($refund->account_id)->increment('balance', (float) $refund->amount);
            $this->sync($original);

            return $refund;
        });
    }

    /** Spáruje už existujúci príjem s výdavkom (napr. dobropis stiahnutý z banky). */
    public function link(Transaction $refund, Transaction $original): void
    {
        if ($refund->id === $original->id) {
            throw ValidationException::withMessages(['refund_for_id' => 'Transakciu nemožno spárovať samú so sebou.']);
        }
        if ($refund->type !== 'income') {
            throw ValidationException::withMessages(['refund_for_id' => 'Ako vrátenie sa dá spárovať len príjem.']);
        }
        if ($refund->refunds()->exists()) {
            throw ValidationException::withMessages(['refund_for_id' => 'Táto transakcia už má vlastné vrátenia.']);
        }

        $this->assertRefundable($original);
        $this->assertRefundFits($original, (float) $refund->amount, $refund->id);

        DB::transaction(function () use ($refund, $original) {
            $previous = $refund->refundFor;

            // Vrátenie nemá vlastnú kategóriu — patrí do kategórie pôvodného nákupu
            $refund->update(['refund_for_id' => $original->id, 'category_id' => null]);

            if ($previous && $previous->id !== $original->id) {
                $this->sync($previous);
            }
            $this->sync($original);
        });
    }

    /** Rozpáruje vrátenie — ostane z neho bežný príjem bez kategórie. */
    public function unlink(Transaction $refund): void
    {
        $original = $refund->refundFor;
        if (! $original) {
            return;
        }

        DB::transaction(function () use ($refund, $original) {
            $refund->update(['refund_for_id' => null]);
            $this->sync($original);
        });
    }

    /** Prepočíta refunded_amount na výdavku podľa spárovaných vrátení. */
    public function sync(Transaction $original): void
    {
        $sum = (float) $original->refunds()->sum('amount');

        $original->forceFill(['refunded_amount' => round($sum, 2)])->save();
    }

    /** Výdavok, na ktorý sa vrátenie dá naviazať. */
    protected function assertRefundable(Transaction $original): void
    {
        if ($original->type !== 'expense') {
            throw ValidationException::withMessages(['refund_for_id' => 'Vrátenie sa dá naviazať len na výdavok.']);
        }
        if ($original->isRefund()) {
            throw ValidationException::withMessages(['refund_for_id' => 'Vrátenie sa nedá naviazať na iné vrátenie.']);
        }
    }

    /**
     * Súčet vrátení nesmie presiahnuť pôvodný výdavok — inak by z nákupu
     * vyšiel záporný náklad a analýzy by klamali.
     *
     * @param  int|null  $ignoreId  vrátenie, ktoré sa práve upravuje/páruje
     */
    public function assertRefundFits(Transaction $original, float $amount, ?int $ignoreId = null): void
    {
        $already = (float) $original->refunds()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->sum('amount');
        $remaining = round((float) $original->amount - $already, 2);

        if (round($amount, 2) > $remaining) {
            throw ValidationException::withMessages([
                'amount' => 'Vrátiť sa dá najviac '.number_format($remaining, 2, ',', ' ').' € — toľko z výdavku ostáva.',
            ]);
        }
    }
}
