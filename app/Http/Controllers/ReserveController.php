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
            'income_type' => ['required', Rule::in(['stable', 'variable', 'self_employed', 'mixed', 'student'])],
            'graduation_year' => ['nullable', 'integer', 'min:2026', 'max:2060'],
            'post_graduation_expenses' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'after_school_city' => ['nullable', Rule::in(array_keys(config('gros.reference.rent.by_city')))],
            'after_school_size' => ['nullable', Rule::in(array_keys(config('gros.reference.rent.by_size')))],
            'after_school_share' => ['nullable', 'numeric', 'min:0.25', 'max:1'],
            'household' => ['required', Rule::in(['single', 'dual_income', 'single_income_couple', 'dependents'])],
            'unemployment_benefit' => ['required', 'boolean'],
            'health_risk' => ['required', 'boolean'],
            'source' => ['required', Rule::in(['all_cash', 'account', 'cash_minus_month'])],
            'account_id' => ['nullable', 'integer'],
            'months_override' => ['nullable', 'numeric', 'min:0', 'max:24'],
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
