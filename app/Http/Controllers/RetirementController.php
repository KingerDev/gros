<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmergencyFundService;
use App\Services\FinancialProfileService;
use App\Services\PortfolioAnalyticsService;
use App\Services\RetirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RetirementController extends Controller
{
    public function __construct(
        protected FinancialProfileService $profiles,
        protected PortfolioAnalyticsService $portfolio,
        protected EmergencyFundService $reserve,
    ) {}

    /** Kľúče pravidla vkladu, ktoré prichádzajú z formulára. */
    protected const RULE_KEYS = ['mode', 'pct', 'later_from', 'later_income', 'later_fixed', 'later_monthly'];

    public function index(Request $request, RetirementService $svc): Response
    {
        $user = $request->user();
        $profile = $this->profiles->forUser($user);
        $contributions = $this->portfolio->investmentContributions($user);
        $housing = $user->retire_housing === null ? null : (float) $user->retire_housing;
        $rule = $this->contributionRule($user, $profile, $contributions, [], $housing);

        $plan = $svc->resolveParams($user, $this->ruleOverrides($rule));
        // pole výdavkov ukazuje len to, čo si používateľ sám zadal — inak sa
        // počíta automaticky zo spotreby a bývania (pozri spending_basis)
        $plan['spending'] = $user->retire_spending === null ? null : (float) $user->retire_spending;
        $plan['housing'] = $housing;
        // prázdne pole = odporúčaná miera výberu
        $plan['withdrawal'] = $user->retire_withdrawal === null ? null : (float) $user->retire_withdrawal;

        $plan['crypto_mode'] = $user->retire_crypto_mode ?? 'exclude';

        return Inertia::render('gros/Retirement', [
            'plan' => $plan,
            'rule' => $rule,
            'averageWage' => ['source' => __('gros.server.average_wage_source')] + config('gros.average_wage'),
            'spending_basis' => $this->spendingBasis($profile, $housing),
            'profile' => [
                'measured' => $profile['measured'],
                'assets' => $profile['assets'],
                'investable_cash' => $this->investableCash($user, $profile),
            ],
            'contributions' => $contributions,
            'allocation' => $this->allocation($user),
        ]);
    }

    /** Prepočet projekcie (JSON) — volá sa pri každej zmene vstupov. */
    public function simulate(Request $request, RetirementService $svc): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        $user = $request->user();
        $profile = $this->profiles->forUser($user);

        $housing = $request->has('housing') ? $this->nullableFloat($request->input('housing')) : ($user->retire_housing === null ? null : (float) $user->retire_housing);
        $rule = $this->contributionRule($user, $profile, $this->portfolio->investmentContributions($user), $this->ruleInput($request), $housing);

        $overrides = array_filter([
            'year' => $data['year'] ?? null,
            'birth_year' => $request->has('birth_year') ? ($data['birth_year'] ?? '') : null,
            'withdrawal' => $request->has('withdrawal') ? ($data['withdrawal'] ?? '') : null,
            'spending' => $data['spending'] ?? null,
            'crypto_mode' => $data['crypto_mode'] ?? null,
        ], fn ($v) => $v !== null) + $this->ruleOverrides($rule) + ['crypto_value' => $this->cryptoValue($user)];

        // bez vlastnej sumy sa výdavky v dôchodku skladajú z dnešnej spotreby
        // a bývania — rovnako, ako to stránka ukazuje
        $basis = $this->spendingBasis($profile, $housing);
        if (! isset($overrides['spending']) && $user->retire_spending === null && $basis['auto'] > 0) {
            $overrides['spending'] = $basis['auto'];
        }

        $start = (float) $profile['assets']['portfolio'];
        if ($request->boolean('include_cash')) {
            $start += $this->investableCash($user, $profile);
        }

        $result = $svc->project($user, $start, $overrides);

        // ako by vyšiel rok slobody pri každom spôsobe počítania krypta —
        // nech je vidieť, koľko z plánu na ňom stojí
        $alternatives = [];
        if ($result['crypto']['value'] > 0) {
            foreach (RetirementService::CRYPTO_MODES as $mode) {
                $alt = $mode === $result['crypto']['mode'] ? $result : $svc->project($user, $start, ['crypto_mode' => $mode] + $overrides);
                $mid = collect($alt['scenarios'])->firstWhere('key', 'mid');
                $alternatives[$mode] = ['final' => $mid['final'], 'freedom_year' => $alt['freedom']['year'] ?? null];
            }
        }

        return response()->json($result + ['rule' => $rule, 'crypto_alternatives' => $alternatives]);
    }

    /** Uloží plán do profilu. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(required: true));

        $rule = [];
        foreach (self::RULE_KEYS as $key) {
            $rule[$key] = $data['contribution_'.$key] ?? null;
        }
        $rule['mode'] ??= 'fixed';

        $request->user()->update([
            'retire_year' => $data['year'],
            'retire_birth_year' => $data['birth_year'] ?? null,
            'retire_monthly' => $data['monthly'],
            'retire_contribution' => $rule,
            // prázdne = odporúčaná podľa dĺžky dôchodku
            'retire_withdrawal' => $data['withdrawal'] ?? null,
            // voliteľné polia sa v requeste vôbec nemusia objaviť
            'retire_spending' => $data['spending'] ?? null,
            'retire_housing' => $data['housing'] ?? null,
            'retire_crypto_mode' => $data['crypto_mode'] ?? null,
        ]);

        return back()->with('success', 'Plán uložený.');
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(bool $required): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            'year' => [$req, 'integer', 'min:2027', 'max:2120'],
            'birth_year' => ['nullable', 'integer', 'min:1920', 'max:2025'],
            'monthly' => [$req, 'numeric', 'min:0', 'max:1000000'],
            'withdrawal' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'spending' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'housing' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'include_cash' => ['nullable', 'boolean'],
            'crypto_mode' => ['nullable', 'in:'.implode(',', RetirementService::CRYPTO_MODES)],
            'contribution_mode' => ['nullable', 'in:fixed,percent'],
            'contribution_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contribution_later_from' => ['nullable', 'integer', 'min:2026', 'max:2120'],
            'contribution_later_income' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'contribution_later_fixed' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'contribution_later_monthly' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Polia pravidla, ktoré request naozaj poslal — aj prázdne, lebo prázdne
     * znamená „zruš", nie „nechaj uložené".
     *
     * @return array<string, mixed>
     */
    protected function ruleInput(Request $request): array
    {
        $out = [];
        foreach (self::RULE_KEYS as $key) {
            if ($request->has('contribution_'.$key)) {
                $out[$key] = $request->input('contribution_'.$key);
            }
        }
        if ($request->has('monthly')) {
            $out['fixed_monthly'] = $request->input('monthly');
        }

        return $out;
    }

    /**
     * Mesačný vklad podľa pravidla.
     *
     * Pevná suma: zadaná, alebo zmeraná z nákupov. Percento: podiel z príjmu
     * po povinných platbách (splátky úverov — lízing a pod.), teda presne tak,
     * ako sa človek rozhoduje pri výplate. Voliteľná druhá fáza „od roku X"
     * počíta s iným príjmom a inými povinnými platbami — predvolene splátky
     * plus bývanie neskôr.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $contributions
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function contributionRule(User $user, array $profile, array $contributions, array $input, ?float $housingLater): array
    {
        $saved = $user->retire_contribution ?? [];
        $get = fn (string $key) => array_key_exists($key, $input) ? $input[$key] : ($saved[$key] ?? null);

        $mode = $get('mode') === 'percent' ? 'percent' : 'fixed';
        $pct = $this->nullableFloat($get('pct')) ?? 25.0;

        $income = (float) ($profile['measured']['income'] ?? 0);
        $fixed = round((float) $user->loans()->where('kind', 'owe')->where('balance', '>', 0)->sum('payment'), 2);
        $base = max(0, $income - $fixed);

        $measured = (float) ($contributions['recommended'] ?? 0);
        $fixedMonthly = $this->nullableFloat($input['fixed_monthly'] ?? null)
            ?? ((float) $user->retire_monthly > 0 ? (float) $user->retire_monthly : $measured);

        $monthly = $mode === 'percent' ? round($base * $pct / 100, 2) : $fixedMonthly;

        $later = null;
        $laterFrom = $this->nullableFloat($get('later_from'));
        if ($laterFrom !== null) {
            $laterIncome = $this->nullableFloat($get('later_income'));
            $laterFixedOwn = $this->nullableFloat($get('later_fixed'));
            $laterFixed = $laterFixedOwn ?? round($fixed + ($housingLater ?? 0), 2);
            $laterBase = $laterIncome === null ? null : max(0, $laterIncome - $laterFixed);
            $laterMonthly = $mode === 'percent'
                ? ($laterBase === null ? null : round($laterBase * $pct / 100, 2))
                : $this->nullableFloat($get('later_monthly'));

            $later = [
                'from' => (int) $laterFrom,
                'income' => $laterIncome,
                'fixed' => $laterFixed,
                'fixed_is_own' => $laterFixedOwn !== null,
                'base' => $laterBase,
                'monthly' => $laterMonthly,
            ];
        }

        return [
            'mode' => $mode,
            'pct' => $pct,
            'income' => round($income, 2),
            'fixed' => $fixed,
            'base' => round($base, 2),
            'monthly' => $monthly,
            'measured' => $measured,
            'later' => $later,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    protected function ruleOverrides(array $rule): array
    {
        return [
            'monthly' => $rule['monthly'],
            'monthly_later' => $rule['later']['monthly'] ?? null,
            'later_from' => $rule['later']['from'] ?? null,
        ];
    }

    /**
     * Podiel portfólia mimo ETF. Scenáre 3/5/7 % predpokladajú rozložené
     * svetové akcie — na krypto ani jednotlivé akcie nesedia.
     *
     * @return array<string, mixed>
     */
    protected function allocation(User $user): array
    {
        $byKind = [];
        foreach ($user->investments()->get() as $i) {
            $byKind[$i->kind] = ($byKind[$i->kind] ?? 0) + (float) $i->value;
        }
        $total = array_sum($byKind);
        $other = $total > 0 ? ($total - ($byKind['etf'] ?? 0)) / $total * 100 : 0.0;

        return [
            'other_pct' => round($other, 1),
            'crypto_pct' => $total > 0 ? round(($byKind['crypto'] ?? 0) / $total * 100, 1) : 0.0,
            'stock_pct' => $total > 0 ? round(($byKind['stock'] ?? 0) / $total * 100, 1) : 0.0,
        ];
    }

    /** Aktuálna hodnota krypta v portfóliu. */
    protected function cryptoValue(User $user): float
    {
        return round((float) $user->investments()->where('kind', 'crypto')->get()->sum(fn ($i) => (float) $i->value), 2);
    }

    protected function nullableFloat(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    /**
     * Z čoho sa skladajú výdavky v dôchodku, keď si ich používateľ nezadal:
     * dnešná spotreba (vrátane jednorazoviek — prichádzajú stále, bez vkladov
     * do portfólia), v ktorej sa dnešné bývanie nahradí odhadom budúceho.
     *
     * Kto dnes za bývanie neplatí, má spotrebu podhodnotenú — preto
     * `missing_housing`, aby to stránka vedela povedať.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function spendingBasis(array $profile, ?float $housingLater): array
    {
        $consumption = (float) ($profile['measured']['consumption'] ?? 0);
        $housingNow = (float) ($profile['measured']['housing'] ?? 0);
        $auto = $housingLater === null ? $consumption : max(0, $consumption - $housingNow) + $housingLater;

        return [
            'consumption' => round($consumption, 2),
            'housing_now' => round($housingNow, 2),
            'housing_later' => $housingLater,
            'auto' => round($auto, 2),
            'missing_housing' => $housingLater === null && $housingNow < 50,
            'months' => (int) ($profile['measured']['months'] ?? 0),
        ];
    }

    /**
     * Hotovosť nad cieľ núdzovej rezervy — jediná časť hotovosti, ktorú má
     * zmysel počítať ako investovateľnú.
     *
     * @param  array<string, mixed>  $profile
     */
    protected function investableCash(User $user, array $profile): float
    {
        $target = (float) $this->reserve->forUser($user)['target'];

        return round(max(0, (float) $profile['assets']['cash'] - $target), 2);
    }
}
