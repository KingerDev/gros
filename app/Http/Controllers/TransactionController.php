<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Filtrovanie (typ/obdobie) a CSV export rieši frontend nad celým zoznamom.
        $transactions = $user->transactions()
            ->with(['account:id,name,color', 'toAccount:id,name,color'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('gros/Transactions', [
            'transactions' => $transactions,
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name']),
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

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $this->validated($request);

        DB::transaction(function () use ($transaction, $data) {
            $this->revertBalance($transaction);   // vráť späť starý efekt
            $transaction->update($data);
            $this->applyBalance($transaction);     // aplikuj nový efekt
        });

        return back()->with('success', 'Transakcia upravená.');
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($transaction) {
            $this->revertBalance($transaction);
            $transaction->delete();
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
    protected function validated(Request $request): array
    {
        $userId = $request->user()->id;
        $type = $request->input('type');

        $rules = [
            'type' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:191'],
        ];

        if ($type === 'transfer') {
            $rules['to_account_id'] = ['required', 'different:account_id', Rule::exists('accounts', 'id')->where('user_id', $userId)];
        } else {
            $rules['category_id'] = ['required', Rule::exists('categories', 'id')->where('user_id', $userId)];
        }

        $data = $request->validate($rules);

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

        return $data;
    }
}
