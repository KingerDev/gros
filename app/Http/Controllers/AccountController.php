<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->accounts()->orderByDesc('balance')->get();

        return Inertia::render('gros/Accounts', [
            'accounts' => $accounts,
            'total' => (float) $accounts->sum('balance'),
        ]);
    }

    public function show(Request $request, Account $account): Response
    {
        abort_unless($account->user_id === $request->user()->id, 403);

        // Transakcie účtu + prevody, ktoré na účet prichádzajú (to_account_id)
        $txns = $request->user()->transactions()
            ->where(fn ($q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->with(['account:id,name,color', 'toAccount:id,name,color'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        // Príjmy/výdavky účtu = len skutočné príjmy/výdavky (prevody sa nerátajú)
        $income = (float) $txns->where('type', 'income')->where('account_id', $account->id)->sum('amount');
        $expense = (float) $txns->where('type', 'expense')->where('account_id', $account->id)->sum('amount');

        return Inertia::render('gros/AccountDetail', [
            'account' => $account,
            'transactions' => $txns,
            'accounts' => $request->user()->accounts()->orderBy('name')->get(['id', 'name']),
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:60'],
            'balance' => ['required', 'numeric'],
        ]);

        $count = $request->user()->accounts()->count();
        $palette = config('gros.palette');
        $data['color'] = $palette[$count % count($palette)];

        $request->user()->accounts()->create($data);

        return back()->with('success', 'Účet pridaný.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:60'],
            'balance' => ['required', 'numeric'],
        ]);

        $account->update($data);

        return back()->with('success', 'Účet upravený.');
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 403);

        $account->delete();

        return redirect()->route('accounts.index')->with('success', 'Účet zmazaný.');
    }
}
