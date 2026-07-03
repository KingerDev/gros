<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $budgets = $user->budgets()->get();
        $today = CarbonImmutable::today();

        $rows = $budgets->map(function (Budget $b) use ($user, $today) {
            $from = match ($b->period) {
                'week' => $today->startOfWeek(),
                'year' => $today->startOfYear(),
                default => $today->startOfMonth(),
            };

            $spent = (float) $user->transactions()
                ->where('type', 'expense')
                ->where('category_id', $b->category_id)
                ->where('date', '>=', $from->toDateString())
                ->sum('amount');

            return [
                'id' => $b->id,
                'category_id' => $b->category_id,
                'limit_amount' => (float) $b->limit_amount,
                'period' => $b->period,
                'spent' => $spent,
            ];
        })->values();

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
