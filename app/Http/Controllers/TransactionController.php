<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\AnalyticsService;
use App\Services\CategorySuggester;
use App\Services\RefundService;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $user = $request->user();
        $period = Period::fromRequest($request);

        // Obdobie riešime na serveri (je zdieľané s prehľadom aj analýzami),
        // filter typu a CSV export si nad načítaným zoznamom robí frontend.
        $transactions = $period->apply(
            $user->transactions()
                ->with(['account:id,name,color', 'toAccount:id,name,color', 'refunds:id,refund_for_id,amount,date,note,account_id'])
        )
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('gros/Transactions', [
            'period' => $period->toArray(),
            'dataRange' => $analytics->dataRange($user),
            'transactions' => $transactions,
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Kategória, ktorú si na rovnakú poznámku používal doteraz (JSON pre formulár). */
    public function suggestCategory(Request $request, CategorySuggester $suggester): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);

        return response()->json([
            'category_id' => $suggester->suggest($request->user(), $data['note'], $data['type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $data) {
            $txn = $request->user()->transactions()->create($data);
            $this->applyBalance($txn);
        });

        return back()->with('success', $data['type'] === 'transfer' ? 'Prevod pridaný.' : 'Transakcia pridaná.');
    }

    public function update(Request $request, Transaction $transaction, RefundService $refunds): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $this->validated($request, $transaction);

        DB::transaction(function () use ($transaction, $data, $refunds) {
            $this->revertBalance($transaction);   // vráť späť starý efekt
            $transaction->update($data);
            $this->applyBalance($transaction);     // aplikuj nový efekt

            // Zmena sumy vrátenia mení aj to, koľko ostáva z pôvodného nákupu
            if ($transaction->refundFor) {
                $refunds->sync($transaction->refundFor);
            }
        });

        return back()->with('success', $transaction->isRefund() ? 'Vrátenie upravené.' : 'Transakcia upravená.');
    }

    /** Zapne/vypne vylúčenie z analýzy (rýchla akcia zo zoznamu). Zostatky účtov ostávajú nedotknuté. */
    public function exclusion(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'excluded_from_analytics' => ['required', 'boolean'],
            'exclusion_reason' => ['required_if:excluded_from_analytics,true,1', 'nullable', 'string', 'max:191'],
        ]);

        $excluded = (bool) $data['excluded_from_analytics'];

        $transaction->update([
            'excluded_from_analytics' => $excluded,
            'exclusion_reason' => $excluded ? $data['exclusion_reason'] : null,
        ]);

        return back()->with('success', $excluded ? 'Transakcia vylúčená z analýzy.' : 'Transakcia vrátená do analýzy.');
    }

    /**
     * Nové vrátenie peňazí k výdavku: príjem na účet + zníženie výdavku v analýzach.
     */
    public function storeRefund(Request $request, Transaction $transaction, RefundService $refunds): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $refunds->create($transaction, $data);

        return back()->with('success', 'Vrátenie zaznamenané.');
    }

    /**
     * Spáruje/rozpáruje existujúci príjem s výdavkom.
     * refund_for_id = id výdavku, alebo null pre rozpárovanie.
     */
    public function refundLink(Request $request, Transaction $transaction, RefundService $refunds): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'refund_for_id' => ['nullable', Rule::exists('transactions', 'id')->where('user_id', $request->user()->id)],
        ]);

        if (empty($data['refund_for_id'])) {
            $refunds->unlink($transaction);

            return back()->with('success', 'Vrátenie rozpárované.');
        }

        $original = $request->user()->transactions()->findOrFail($data['refund_for_id']);
        $refunds->link($transaction, $original);

        return back()->with('success', 'Príjem spárovaný ako vrátenie.');
    }

    public function destroy(Request $request, Transaction $transaction, RefundService $refunds): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($transaction, $refunds) {
            $original = $transaction->refundFor;

            $this->revertBalance($transaction);
            $transaction->delete();

            if ($original) {
                $refunds->sync($original);
            }
        });

        return back()->with('success', 'Transakcia zmazaná.');
    }

    /**
     * Premietne transakciu do zostatkov účtov:
     *  príjem  → účet + suma
     *  výdavok → účet − suma
     *  prevod  → zdroj − suma, cieľ + suma
     */
    protected function applyBalance(Transaction $t): void
    {
        $amount = (float) $t->amount;
        if ($t->type === 'income') {
            Account::whereKey($t->account_id)->increment('balance', $amount);
        } elseif ($t->type === 'expense') {
            Account::whereKey($t->account_id)->decrement('balance', $amount);
        } elseif ($t->type === 'transfer' && $t->to_account_id) {
            Account::whereKey($t->account_id)->decrement('balance', $amount);
            Account::whereKey($t->to_account_id)->increment('balance', $amount);
        }
    }

    /** Vráti efekt transakcie späť (opak applyBalance). */
    protected function revertBalance(Transaction $t): void
    {
        $amount = (float) $t->amount;
        if ($t->type === 'income') {
            Account::whereKey($t->account_id)->decrement('balance', $amount);
        } elseif ($t->type === 'expense') {
            Account::whereKey($t->account_id)->increment('balance', $amount);
        } elseif ($t->type === 'transfer' && $t->to_account_id) {
            Account::whereKey($t->account_id)->increment('balance', $amount);
            Account::whereKey($t->to_account_id)->decrement('balance', $amount);
        }
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Transaction $existing = null): array
    {
        $userId = $request->user()->id;

        // Spárované vrátenie ostáva príjmom bez kategórie — typ sa mu meniť nedá
        $isRefund = $existing?->isRefund() ?? false;
        $type = $isRefund ? 'income' : $request->input('type');

        $rules = [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:191'],
            'excluded_from_analytics' => ['boolean'],
            'exclusion_reason' => ['required_if:excluded_from_analytics,true,1', 'nullable', 'string', 'max:191'],
        ];

        if (! $isRefund) {
            $rules['type'] = ['required', Rule::in(['income', 'expense', 'transfer'])];

            if ($type === 'transfer') {
                $rules['to_account_id'] = ['required', 'different:account_id', Rule::exists('accounts', 'id')->where('user_id', $userId)];
            } else {
                $rules['category_id'] = ['required', Rule::exists('categories', 'id')->where('user_id', $userId)];
            }
        }

        $data = $request->validate($rules);

        // Vylúčenie z analýzy: bez zaškrtnutia sa dôvod nedrží
        $data['excluded_from_analytics'] = (bool) ($data['excluded_from_analytics'] ?? false);
        $data['exclusion_reason'] = $data['excluded_from_analytics'] ? ($data['exclusion_reason'] ?? null) : null;

        if ($isRefund) {
            // Nová suma vrátenia sa musí zmestiť do zvyšku pôvodného nákupu
            app(RefundService::class)->assertRefundFits($existing->refundFor, (float) $data['amount'], $existing->id);

            return $data + ['type' => 'income', 'category_id' => null, 'to_account_id' => null];
        }

        if ($type === 'transfer') {
            $data['category_id'] = null;
        } else {
            $data['to_account_id'] = null;

            // Typ kategórie musí sedieť s typom transakcie
            $category = $request->user()->categories()->find($data['category_id']);
            if ($category && $category->type !== $type) {
                abort(422, 'Kategória nezodpovedá typu transakcie.');
            }
        }

        // Výdavok, z ktorého sa už niečo vrátilo, sa nesmie zmenšiť pod vrátenú sumu
        $refunded = (float) ($existing?->refunded_amount ?? 0);
        if ($refunded > 0 && (round((float) $data['amount'], 2) < $refunded || $type !== 'expense')) {
            throw ValidationException::withMessages([
                'amount' => 'Z tohto výdavku je vrátených '.number_format($refunded, 2, ',', ' ').' € — najprv rozpáruj vrátenia.',
            ]);
        }

        return $data;
    }
}
