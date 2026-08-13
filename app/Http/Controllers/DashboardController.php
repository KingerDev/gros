<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\AnalyticsService;
use App\Services\AnomalyDetector;
use App\Services\FinanceService;
use App\Services\FinancialProfileService;
use App\Services\NetWorthService;
use App\Services\RetirementService;
use App\Services\SpendingPlanService;
use App\Support\Period;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        FinanceService $finance,
        AnalyticsService $analytics,
        SpendingPlanService $plan,
        NetWorthService $netWorth,
        FinancialProfileService $profiles,
        RetirementService $retirement,
        AnomalyDetector $anomalies,
    ): Response {
        $user = $request->user();
        $period = Period::fromRequest($request);

        $portfolio = $finance->portfolio($user);
        $cash = $finance->cash($user);
        $portValue = $portfolio['value'];
        $loanOwed = (float) $user->loans()->where('kind', 'owe')->sum('balance');

        // Obdobím riadené: príjmy/výdavky/úspory + kategórie + top výdavky
        $sum = $analytics->summary($user, $period);
        $prevPeriod = $period->previous();
        $prevSum = $prevPeriod ? $analytics->summary($user, $prevPeriod) : null;
        $spendCats = $analytics->byCategory($user, $period, 'expense');
        $topExpenses = $period->apply($user->transactions()->analyzed()->where('type', 'expense'))
            ->orderByDesc(Transaction::netExpression())
            ->limit(5)
            ->get(['category_id', 'amount', 'refunded_amount', 'note'])
            ->map(fn ($t) => [
                'category_id' => $t->category_id,
                'note' => $t->note,
                'amount' => $t->net_amount,
            ]);

        // Investičné pozície
        $holdings = $user->investments()->get()->map(fn ($i) => [
            'ticker' => $i->ticker,
            'name' => $i->name,
            'value' => $i->value,
            'color' => $i->color,
        ]);

        // Zloženie majetku
        $assetParts = collect([
            ['name' => 'Hotovosť a účty', 'value' => $cash, 'color' => '#4c8dff'],
            ['name' => 'Investície', 'value' => $portValue, 'color' => '#9775fa'],
        ])->filter(fn ($p) => $p['value'] > 0)->values();

        // Rozpočty: 4 najviac čerpané (podiel spent/limit)
        $budgets = $finance->budgetProgress($user)
            ->sortByDesc(fn ($b) => $b['limit_amount'] > 0 ? $b['spent'] / $b['limit_amount'] : 0)
            ->take(4)
            ->values();

        return Inertia::render('gros/Dashboard', [
            'period' => $period->toArray(),
            'dataRange' => $analytics->dataRange($user),
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'netWorth' => $cash + $portValue - $loanOwed,
                'grossWorth' => $cash + $portValue,
                'cash' => $cash,
                'income' => $sum['income'],
                'expense' => $sum['expense'],
                'saved' => $sum['net'],
                'savedPct' => $sum['savingsRate'],
            ],
            'prevStats' => $prevSum ? [
                'label' => $prevPeriod->label,
                'income' => $prevSum['income'],
                'expense' => $prevSum['expense'],
                'saved' => $prevSum['net'],
            ] : null,
            'netWorthSeries' => $netWorth->monthlySeries($user),
            'reserve' => $finance->reserve($user),
            // nezvyčajné výdavky — čisto štatisticky, bez AI
            'anomalies' => $anomalies->recent($user),
            'aiConfigured' => (bool) config('services.openai.key'),
            'savingsRate' => $profiles->savingsRateReport(
                $user,
                $retirement->realReturnAssumption($user),
                (float) ($user->retire_withdrawal ?? 4)
            ),
            'insights' => array_slice($analytics->insights($user, $period), 0, 2),
            'portfolio' => $portfolio,
            'spendCats' => $spendCats,
            'upcoming' => $finance->upcomingPayments($user, 30),
            'holdings' => $holdings,
            'assetParts' => $assetParts,
            'topExpenses' => $topExpenses,
            'budgets' => $budgets,
            'goals' => $user->goals()->orderBy('created_at')->get()->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'target_amount' => (float) $g->target_amount,
                'saved_amount' => (float) $g->saved_amount,
                'color' => $g->color,
                'deadline' => $g->deadline?->toDateString(),
            ]),
            'history' => $finance->monthlyHistory($user, 6),
            'loanOwed' => $loanOwed,
            'plan' => $plan->current($user),
        ]);
    }
}
