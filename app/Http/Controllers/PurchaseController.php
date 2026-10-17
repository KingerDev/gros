<?php

namespace App\Http\Controllers;

use App\Services\FinancialProfileService;
use App\Services\OpportunityCostService;
use App\Services\RetirementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * „Oplatí sa mi to?" — prevedie cenu nákupu na to, čo z tej sumy mohlo byť,
 * keby išla do portfólia. V troch scenároch výnosu, lebo budúci výnos nikto
 * nepozná — a v dnešných eurách, aby sa dal porovnať s dnešnými cenami.
 *
 * Nemá to od nákupu odhovárať. Má ukázať cenu v mene, ktorú peniaze
 * neukazujú — a keď to aj tak kúpiš, aspoň vieš, že to naozaj chceš.
 */
class PurchaseController extends Controller
{
    public function __construct(
        protected FinancialProfileService $profiles,
        protected OpportunityCostService $cost,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $profile = $this->profiles->forUser($user);

        return Inertia::render('gros/Purchase', [
            'context' => [
                'scenarios' => RetirementService::SCENARIOS,
                'retire_year' => (int) ($user->retire_year ?? 2065),
                'monthly_income' => (float) ($profile['measured']['income'] ?? 0),
                'monthly_surplus' => (float) ($profile['measured']['savings'] ?? 0),
            ],
        ]);
    }

    /** Čím by suma bola po rokoch, v troch scenároch (JSON). */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'recurring' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $profile = $this->profiles->forUser($user);
        $amount = (float) $data['amount'];
        $recurring = (bool) ($data['recurring'] ?? false);

        $thisYear = CarbonImmutable::today()->year;
        $toRetirement = max(1, (int) ($user->retire_year ?? 2065) - $thisYear);

        $horizons = [];
        foreach (array_unique([5, 10, 20, $toRetirement]) as $years) {
            if ($years > $toRetirement) {
                continue;
            }
            $row = ['years' => $years, 'year' => $thisYear + $years];
            foreach (RetirementService::SCENARIOS as $key => $s) {
                $row[$key] = $recurring
                    ? $this->cost->fromMonthly($amount, $years, $s['return'])
                    : $this->cost->fromLumpSum($amount, $years, $s['return']);
            }
            $horizons[] = $row;
        }
        usort($horizons, fn ($a, $b) => $a['years'] <=> $b['years']);

        $income = (float) ($profile['measured']['income'] ?? 0);
        $surplus = (float) ($profile['measured']['savings'] ?? 0);

        return response()->json([
            'ok' => true,
            'purchase' => [
                'amount' => round($amount, 2),
                'recurring' => $recurring,
                'horizons' => $horizons,
            ],
            // aby suma dostala aj mierku voči tomu, čo mesačne zarobíš
            'context' => [
                'income_share' => $income > 0 ? round($amount / $income * 100, 1) : null,
                'income_days' => $income > 0 ? round($amount / ($income / 30), 1) : null,
                'surplus_months' => $surplus > 0 ? round($amount / $surplus, 1) : null,
            ],
        ]);
    }
}
