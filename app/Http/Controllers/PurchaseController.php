<?php

namespace App\Http\Controllers;

use App\Services\EmergencyFundService;
use App\Services\FinancialProfileService;
use App\Services\PortfolioAnalyticsService;
use App\Services\RetirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „Oplatí sa mi to?" — prevedie cenu nákupu na to, čo z tej sumy mohlo byť,
 * a hlavne na čas: o koľko neskôr kvôli nej príde finančná sloboda.
 *
 * Nemá to od nákupu odhovárať. Má ukázať cenu v mene, ktorú peniaze
 * neukazujú — a keď to aj tak kúpiš, aspoň vieš, že to naozaj chceš.
 */
class PurchaseController extends Controller
{
    public function __construct(
        protected FinancialProfileService $profiles,
        protected PortfolioAnalyticsService $portfolio,
        protected EmergencyFundService $reserve,
        protected RetirementService $retirement,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $profile = $this->profiles->forUser($user);

        return Inertia::render('gros/Purchase', [
            'context' => [
                'real_return' => $this->retirement->realReturnAssumption($user),
                'retire_year' => (int) ($user->retire_year ?? 2065),
                'monthly_income' => (float) ($profile['measured']['income'] ?? 0),
                'monthly_surplus' => (float) ($profile['measured']['recurring_savings'] ?? 0),
                'monthly_contribution' => (float) ($this->portfolio->investmentContributions($user)['recommended'] ?? 0),
            ],
        ]);
    }

    /** Dopad jedného nákupu (JSON) — počíta sa nad tou istou projekciou. */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'recurring' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $profile = $this->profiles->forUser($user);
        $reserve = $this->reserve->forUser($user);

        $overrides = [
            'spend' => $data['amount'],
            'spend_monthly' => (bool) ($data['recurring'] ?? false),
            'monthly' => (float) ($this->portfolio->investmentContributions($user)['recommended'] ?? 0),
            'spending' => $reserve['after_school']['estimate'] ?? ($profile['measured']['consumption'] ?? null),
        ];

        $result = $this->retirement->cachedProject(
            $user,
            (float) $profile['assets']['portfolio'],
            array_filter($overrides, fn ($v) => $v !== null)
        );

        $income = (float) ($profile['measured']['income'] ?? 0);
        $surplus = (float) ($profile['measured']['recurring_savings'] ?? 0);
        $amount = (float) $data['amount'];

        return response()->json([
            'ok' => $result['ok'] ?? false,
            'purchase' => $result['purchase'] ?? null,
            'engine' => $result['engine'] ?? null,
            // aby suma dostala aj mierku voči tomu, čo mesačne zarobíš
            'context' => [
                'income_share' => $income > 0 ? round($amount / $income * 100, 1) : null,
                'income_days' => $income > 0 ? round($amount / ($income / 30), 1) : null,
                'surplus_months' => $surplus > 0 ? round($amount / $surplus, 1) : null,
            ],
        ]);
    }
}
