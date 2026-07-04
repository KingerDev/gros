<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request, FinanceService $finance): Response
    {
        $rows = $finance->budgetProgress($request->user());

        return Inertia::render('gros/Budgets', [
            'budgets' => $rows,
            'totals' => [
                'limit' => (float) $rows->sum('limit_amount'),
                'spent' => (float) $rows->sum('spent'),
                'overCount' => $rows->filter(fn ($b) => $b['spent'] > $b['limit_amount'])->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->budgets()->create($this->validated($request));

        return back()->with('success', 'Rozpočet pridaný.');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        $budget->update($this->validated($request));

        return back()->with('success', 'Rozpočet upravený.');
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->user_id === $request->user()->id, 403);

        $budget->delete();

        return back()->with('success', 'Rozpočet zmazaný.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->where('type', 'expense')],
            'limit_amount' => ['required', 'numeric', 'min:0'],
            'period' => ['required', Rule::in(['week', 'month', 'year'])],
        ]);
    }
}
