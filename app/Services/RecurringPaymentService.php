<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Loan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automatické splátky: keď nastane next_payment predplatného alebo úveru,
 * vytvorí JEDNU transakciu na priradený účet, premietne ju do zostatku účtu
 * a posunie next_payment na najbližší budúci termín. Zameškané obdobia
 * nedobieha — aj keď je dátum dávno v minulosti, vznikne len jedna platba.
 */
class RecurringPaymentService
{
    /**
     * Spracuje všetko splatné k danému dňu. Vracia počet vytvorených transakcií.
     * S $user spracuje len jeho platby (lenivé spracovanie pri načítaní stránky).
     */
    public function process(?CarbonImmutable $today = null, ?User $user = null): int
    {
        $today = $today ?? CarbonImmutable::today();

        return $this->processSubscriptions($today, $user) + $this->processLoans($today, $user);
    }

    protected function processSubscriptions(CarbonImmutable $today, ?User $user = null): int
    {
        $created = 0;

        $subs = Subscription::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('account_id')
            ->whereNotNull('next_payment')
            ->whereDate('next_payment', '<=', $today->toDateString())
            ->get();

        foreach ($subs as $sub) {
            DB::transaction(function () use ($sub, $today, &$created) {
                $due = CarbonImmutable::parse($sub->next_payment);

                // Jedna platba za splatné obdobie — zameškané nedobiehame.
                if ($this->post($sub->user_id, (int) $sub->account_id, $sub->category_id, 'expense', (float) $sub->amount, $due, $sub->name, 'subscription', (int) $sub->id)) {
                    $created++;
                }

                // Posuň dátum na najbližší budúci termín.
                do {
                    $due = $sub->cycle === 'yearly' ? $due->addYearNoOverflow() : $due->addMonthNoOverflow();
                } while ($due->lessThanOrEqualTo($today));

                $sub->next_payment = $due->toDateString();
                $sub->save();
            });
        }

        return $created;
    }

    protected function processLoans(CarbonImmutable $today, ?User $user = null): int
    {
        $created = 0;

        $loans = Loan::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('account_id')
            ->whereNotNull('next_payment')
            ->where('payment', '>', 0)
            ->whereDate('next_payment', '<=', $today->toDateString())
            ->get();

        foreach ($loans as $loan) {
            DB::transaction(function () use ($loan, $today, &$created) {
                $remaining = (float) $loan->balance;
                if ($remaining <= 0) {
                    return; // úver je splatený → nič nerobíme
                }

                // 'owe' = splácam (výdavok z účtu), 'lent' = vracajú mi (príjem na účet)
                $type = $loan->kind === 'lent' ? 'income' : 'expense';
                $due = CarbonImmutable::parse($loan->next_payment);

                // Jedna splátka za splatné obdobie — zameškané nedobiehame.
                // Posledná splátka nemôže presiahnuť zostatok.
                $amount = min((float) $loan->payment, $remaining);

                if ($this->post($loan->user_id, (int) $loan->account_id, $loan->category_id, $type, $amount, $due, $loan->name, 'loan', (int) $loan->id)) {
                    $created++;
                }

                $loan->balance = max(0, $remaining - $amount);

                // Posuň dátum na najbližší budúci termín.
                do {
                    $due = $due->addMonthNoOverflow();
                } while ($due->lessThanOrEqualTo($today));

                $loan->next_payment = $due->toDateString();
                $loan->save();
            });
        }

        return $created;
    }

    /**
     * Vytvorí transakciu a premietne ju do zostatku účtu (rovnaká logika ako
     * TransactionController::applyBalance). Vracia true, ak transakcia vznikla.
     */
    protected function post(int $userId, int $accountId, ?int $categoryId, string $type, float $amount, CarbonImmutable $date, ?string $note, string $source, int $sourceId): bool
    {
        if ($amount <= 0) {
            return false;
        }

        Transaction::create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'date' => $date->toDateString(),
            'note' => $note,
            'source' => $source,
            'source_id' => $sourceId,
        ]);

        if ($type === 'income') {
            Account::whereKey($accountId)->increment('balance', $amount);
        } else {
            Account::whereKey($accountId)->decrement('balance', $amount);
        }

        return true;
    }
}
