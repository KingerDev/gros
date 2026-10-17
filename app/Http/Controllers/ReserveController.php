<?php

namespace App\Http\Controllers;

use App\Services\EmergencyFundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReserveController extends Controller
{
    public function index(Request $request, EmergencyFundService $fund): Response
    {
        $user = $request->user();
        $report = $fund->forUser($user);

        return Inertia::render('gros/Reserve', [
            'report' => $report,
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name', 'balance']),
            // strom výdavkových kategórií na označenie nevyhnutných
            'expenseCategories' => $user->categories()->where('type', 'expense')
                ->orderBy('parent_id')->orderBy('position')
                ->get(['id', 'name', 'parent_id', 'color', 'icon']),
            'essentialIds' => $fund->essentialCategoryIds($user, $report['profile']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'months' => ['required', 'numeric', 'min:1', 'max:12'],
            'source' => ['required', Rule::in(['all_cash', 'account', 'cash_minus_month'])],
            'account_id' => ['nullable', 'integer'],
            'essential_category_ids' => ['nullable', 'array'],
            'essential_category_ids.*' => ['integer'],
            'recurring_transaction_ids' => ['nullable', 'array'],
            'recurring_transaction_ids.*' => ['integer'],
        ]);

        $user = $request->user();

        // účet aj kategórie musia patriť používateľovi
        if (! empty($data['account_id']) && ! $user->accounts()->whereKey($data['account_id'])->exists()) {
            $data['account_id'] = null;
        }
        if (! empty($data['essential_category_ids'])) {
            $data['essential_category_ids'] = $user->categories()
                ->whereIn('id', $data['essential_category_ids'])
                ->pluck('id')
                ->all();
        }
        if (! empty($data['recurring_transaction_ids'])) {
            $data['recurring_transaction_ids'] = $user->transactions()
                ->whereIn('id', $data['recurring_transaction_ids'])
                ->pluck('id')
                ->all();
        }

        $user->update(['reserve_profile' => $data]);

        return back()->with('success', 'Nastavenie rezervy uložené.');
    }
}
