<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\SpendingPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PreferenceController extends Controller
{
    public function edit(Request $request, SpendingPlanService $plan): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Preferences', [
            'plan' => [
                'monthlyIncome' => $user->monthly_income !== null ? (float) $user->monthly_income : null,
                'savingsGoal' => (float) $user->savings_goal,
                'estimate' => $plan->current($user)['estimateSuggestion'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'accent' => ['required', 'string', Rule::in(config('gros.accent_options'))],
            'show_decimals' => ['required', 'boolean'],
            'privacy_mode' => ['required', 'boolean'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Nastavenia uložené.');
    }

    public function updatePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'monthly_income' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'savings_goal' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $request->user()->update([
            'monthly_income' => $data['monthly_income'] ?? null,
            'savings_goal' => $data['savings_goal'] ?? 0,
        ]);

        return back()->with('success', 'Mesačný plán uložený.');
    }
}
