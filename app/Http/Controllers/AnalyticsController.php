<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Support\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): Response
    {
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
            'insights' => $analytics->insights($user),
        ]);
    }

    /** Detail kategórie (JSON pre rozklik). */
    public function category(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'integer']]);
        $cat = $request->user()->categories()->find($data['category_id']);
        abort_unless($cat, 404);

        return response()->json($analytics->categoryDetail($request->user(), $cat->id, 12));
    }
}
