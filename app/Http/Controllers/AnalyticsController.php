<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Services\EventService;
use App\Services\FinanceService;
use App\Services\FinancialProfileService;
use App\Services\RetirementService;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(
        Request $request,
        AnalyticsService $analytics,
        FinancialProfileService $profiles,
        RetirementService $retirement,
        EventService $events,
        FinanceService $finance,
    ): Response {
        $user = $request->user();
        $period = Period::fromRequest($request);

        return Inertia::render('gros/Analytics', [
            'period' => $period->toArray(),
            'dataRange' => $analytics->dataRange($user),
            'periodSummary' => $analytics->summary($user, $period),
            'expenseByCategory' => $analytics->byCategory($user, $period, 'expense'),
            'incomeByCategory' => $analytics->byCategory($user, $period, 'income'),
            'monthlySeries' => $analytics->monthlySeries($user, 24),
            'topMerchants' => $analytics->topMerchants($user, $period, 12),
            'events' => $events->inPeriod($user, $period),
            'insights' => $analytics->insights($user, $period),
            'periodReport' => $analytics->periodReport($user, $period),
            'fixedVsVariable' => $analytics->fixedVsVariable($user, 12),
            'savingsRate' => $profiles->savingsRateReport(
                $user,
                $retirement->realReturnAssumption($user),
                (float) ($user->retire_withdrawal ?? 4)
            ),
            // po rokoch — nezávisle od zvoleného obdobia
            'years' => $finance->yearlyHistory($user),
        ]);
    }

    /** Detail kategórie (JSON pre rozklik). */
    public function category(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer']]);
        $cat = $request->user()->categories()->find($data['category_id']);
        abort_unless($cat, 404);

        // Bez query parametrov sa obdobie vezme zo session — teda to isté, aké je zvolené v Analýzach
        return response()->json($analytics->categoryDetail($request->user(), $cat->id, Period::fromRequest($request), 12));
    }
}
