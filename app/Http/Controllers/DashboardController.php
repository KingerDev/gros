<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Services\FinanceService;
use App\Support\Period;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, FinanceService $finance, AnalyticsService $analytics): Response
    {
        $user = $request->user();
        $period = Period::fromRequest($request);

        $portfolio = $finance->portfolio($user);
        $cash = $finance->cash($user);
        $portValue = $portfolio['value'];

        // Obdobím riadené: príjmy/výdavky/úspory + kategórie + top výdavky
        $sum = $analytics->summary($user, $period);
        $spendCats = $analytics->byCategory($user, $period, 'expense');
        $topExpenses = $period->apply($user->transactions()->where('type', 'expense'))
            ->orderByDesc('amount')
            ->limit(5)
            ->get(['category_id', 'amount', 'note'])
            ->map(fn ($t) => [
                'category_id' => $t->category_id,
                'note' => $t->note,
                'amount' => (float) $t->amount,
            ]);

        // Najbližšie platby z predplatného
        $upcoming = $user->subscriptions()->orderBy('next_payment')->limit(4)->get(['name', 'amount', 'next_payment', 'color']);

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

        return Inertia::render('gros/Dashboard', [
            'period' => $period->toArray(),
            'dataRange' => $analytics->dataRange($user),
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'netWorth' => $cash + $portValue,
                'cash' => $cash,
                'income' => $sum['income'],
                'expense' => $sum['expense'],
                'saved' => $sum['net'],
                'savedPct' => $sum['savingsRate'],
            ],
            'portfolio' => $portfolio,
            'spendCats' => $spendCats,
            'upcoming' => $upcoming,
            'holdings' => $holdings,
            'assetParts' => $assetParts,
            'topExpenses' => $topExpenses,
            'history' => $finance->monthlyHistory($user, 6),
            'loanOwed' => (float) $user->loans()->where('kind', 'owe')->sum('balance'),
        ]);
    }
}
