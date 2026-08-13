<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmergencyFundService;
use App\Services\FinancialProfileService;
use App\Services\MarketDataService;
use App\Services\PortfolioAnalyticsService;
use App\Services\RetirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RetirementController extends Controller
{
    public function __construct(
        protected FinancialProfileService $profiles,
        protected PortfolioAnalyticsService $portfolio,
        protected EmergencyFundService $reserve,
    ) {}

    public function index(Request $request, RetirementService $svc, MarketDataService $market): Response
    {
        $user = $request->user();
        $profile = $this->profiles->forUser($user);
        $contributions = $this->portfolio->investmentContributions($user);
        $reserveReport = $this->reserve->forUser($user);

        $thisYear = (int) date('Y');
        $engines = [];
        foreach (MarketDataService::BENCHMARKS as $key => $def) {
            $engines[] = [
                'key' => $key,
                'label' => $def['label'],
                'note' => $def['note'],
                'since' => $def['since'],
                'years' => $thisYear - $def['since'],
                'short_history' => ($thisYear - $def['since']) < MarketDataService::SHORT_HISTORY_YEARS,
            ];
        }
        // najdlhší rad — naň sa dá prepnúť jedným klikom pri krátkej histórii
        $longest = collect($engines)->sortBy('since')->first();

        $infl = $market->inflation('SK');

        return Inertia::render('gros/Retirement', [
            'plan' => $svc->resolveParams($user, $this->measuredDefaults($user, $profile, $contributions, $reserveReport)),
            'profile' => [
                'measured' => $profile['measured'],
                'assets' => $profile['assets'],
                'reserve' => $profile['reserve'],
                'investable_cash' => $this->investableCash($profile),
            ],
            'contributions' => $contributions,
            'engines' => $engines,
            'longestEngine' => $longest,
            'inflationHistory' => $infl ? [
                'avg' => $infl['avg'],
                'avg20' => $infl['avg20'],
                'latest' => $infl['latest'],
                'from' => $infl['from'],
                'to' => $infl['to'],
            ] : null,
        ]);
    }

    /** Prepočet projekcie (JSON) — volá sa pri každej zmene vstupov. */
    public function simulate(Request $request, RetirementService $svc): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2027', 'max:2120'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:60'],
            'monthly' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'index_contributions' => ['nullable', 'boolean'],
            'inflation' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'fees' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'haircut' => ['nullable', 'numeric', 'min:0', 'max:8'],
            'withdrawal' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'engine' => ['nullable', Rule::in(array_keys(MarketDataService::BENCHMARKS))],
            'target_income' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'spending' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'compare' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'include_cash' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $profile = $this->profiles->forUser($user);

        $overrides = array_filter($data, fn ($v) => $v !== null)
            + $this->measuredDefaults($user, $profile, $this->portfolio->investmentContributions($user), $this->reserve->forUser($user));
        unset($overrides['include_cash']);

        $start = (float) $profile['assets']['portfolio'];
        if ($request->boolean('include_cash')) {
            $start += $this->investableCash($profile);
        }

        $result = $svc->cachedProject($user, $start, $overrides);

        // Stresový scenár: koľko z plánu stojí na kolísavých aktívach. Nie je
        // to náhrada hlavného čísla — je to odpoveď na otázku „a čo keby".
        $volatile = (float) $user->investments()->where('kind', 'crypto')->get()->sum(fn ($i) => $i->value);
        if ($volatile > 0 && ($result['ok'] ?? false)) {
            $without = $svc->cachedProject($user, max(0, $start - $volatile), $overrides);
            $result['without_volatile'] = [
                'excluded' => round($volatile, 2),
                'excluded_share' => $start > 0 ? round($volatile / $start * 100, 1) : 0,
                'start_value' => round(max(0, $start - $volatile), 2),
                'real_p50' => $without['final']['real']['p50'] ?? null,
                'income_p50' => $without['final']['income']['p50'] ?? null,
                'freedom_year' => $without['freedom']['year'] ?? null,
                'years_later' => ($without['freedom']['year'] ?? null) && ($result['freedom']['year'] ?? null)
                    ? $without['freedom']['year'] - $result['freedom']['year']
                    : null,
            ];
        }

        return response()->json($result);
    }

    /** Uloží plán do profilu. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2027', 'max:2120'],
            'duration' => ['required', 'integer', 'min:5', 'max:60'],
            'monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'index_contributions' => ['required', 'boolean'],
            'inflation' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'fees' => ['required', 'numeric', 'min:0', 'max:5'],
            'haircut' => ['required', 'numeric', 'min:0', 'max:8'],
            'withdrawal' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'engine' => ['required', Rule::in(array_keys(MarketDataService::BENCHMARKS))],
            'target_income' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'spending' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $request->user()->update([
            'retire_year' => $data['year'],
            'retire_duration' => $data['duration'],
            'retire_monthly' => $data['monthly'],
            'retire_index_contributions' => $data['index_contributions'],
            // voliteľné polia sa v requeste vôbec nemusia objaviť
            'retire_inflation' => $data['inflation'] ?? null,
            'retire_fees' => $data['fees'],
            'retire_haircut' => $data['haircut'],
            'retire_withdrawal' => $data['withdrawal'],
            'retire_engine' => $data['engine'],
            'retire_target_income' => $data['target_income'] ?? null,
            'retire_spending' => $data['spending'] ?? null,
        ]);

        return back()->with('success', 'Plán uložený.');
    }

    /**
     * Predvyplnenie z reálnych dát. Uplatní sa len tam, kde si používateľ nič
     * vlastné neuložil — uložený plán má vždy prednosť pred meraním.
     *
     * Mesačný vklad sa berie z toho, čo naozaj posiela do portfólia, a to
     * mediánom mesiacov: jeden väčší jednorazový vklad nesmie zdvihnúť
     * predpoklad na tempo, ktoré sa nedrží.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $contributions
     * @return array<string, float>
     */
    protected function measuredDefaults(User $user, array $profile, array $contributions, array $reserve): array
    {
        $out = [];

        if ((float) $user->retire_monthly <= 0 && ($contributions['recommended'] ?? 0) > 0) {
            $out['monthly'] = (float) $contributions['recommended'];
        }
        // Do dôchodku sa ide dávno po škole, takže tam patria náklady po nej —
        // dnešné študentské výdavky o roku 2065 nehovoria nič.
        $afterSchool = $reserve['after_school']['estimate'] ?? null;
        $spending = $afterSchool ?? ($profile['measured']['consumption'] ?? 0);

        // v dôchodku už neinvestuješ — presuny do portfólia sem nepatria
        if ($user->retire_spending === null && $spending > 0) {
            $out['spending'] = (float) $spending;
        }

        return $out;
    }

    /**
     * Hotovosť nad rámec šesťmesačnej rezervy — jediná časť hotovosti, ktorú
     * má zmysel počítať ako investovateľnú.
     *
     * @param  array<string, mixed>  $profile
     */
    protected function investableCash(array $profile): float
    {
        $avgExpense = (float) ($profile['reserve']['avgExpense'] ?? 0);

        return round(max(0, (float) $profile['assets']['cash'] - 6 * $avgExpense), 2);
    }
}
