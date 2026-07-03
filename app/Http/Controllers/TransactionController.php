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
            if ($txn->type === 'transfer') {
                $this->applyTransfer((int) $txn->account_id, (int) $txn->to_account_id, (float) $txn->amount);
            }
        });

        return back()->with('success', $data['type'] === 'transfer' ? 'Prevod pridaný.' : 'Transakcia pridaná.');
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        $data = $this->validated($request);

        DB::transaction(function () use ($transaction, $data) {
            // vráť späť starý efekt prevodu
            if ($transaction->type === 'transfer' && $transaction->to_account_id) {
                $this->revertTransfer((int) $transaction->account_id, (int) $transaction->to_account_id, (float) $transaction->amount);
            }

            $transaction->update($data);

            // aplikuj nový efekt prevodu
            if ($transaction->type === 'transfer' && $transaction->to_account_id) {
                $this->applyTransfer((int) $transaction->account_id, (int) $transaction->to_account_id, (float) $transaction->amount);
            }
        });

        return back()->with('success', 'Transakcia upravená.');
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($transaction) {
            if ($transaction->type === 'transfer' && $transaction->to_account_id) {
                $this->revertTransfer((int) $transaction->account_id, (int) $transaction->to_account_id, (float) $transaction->amount);
            }
            $transaction->delete();
        });

        return back()->with('success', 'Transakcia zmazaná.');
    }

    /** Prevod: zdroj − suma, cieľ + suma. */
    protected function applyTransfer(int $fromId, int $toId, float $amount): void
    {
        Account::whereKey($fromId)->decrement('balance', $amount);
        Account::whereKey($toId)->increment('balance', $amount);
    }

    /** Vráti prevod späť: zdroj + suma, cieľ − suma. */
    protected function revertTransfer(int $fromId, int $toId, float $amount): void
    {
        Account::whereKey($fromId)->increment('balance', $amount);
        Account::whereKey($toId)->decrement('balance', $amount);
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
