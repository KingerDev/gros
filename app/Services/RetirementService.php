<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Projekcia portfólia do dôchodku metódou Monte Carlo s block bootstrapom
 * skutočných historických mesačných výnosov (nie s vymysleným „priemerným
 * výnosom"). Vracia pásma pravdepodobnosti — nominálne aj v dnešných eurách.
 *
 * Kľúčový trik: hodnota portfólia je LINEÁRNA v mesačnom vklade
 *      V = start · G + vklad · A
 * kde G je rastový faktor počiatočnej sumy a A anuitný faktor daného scenára.
 * Simulácia sa preto počíta raz a ľubovoľná výška vkladu (aj spätné hľadanie
 * potrebného vkladu) sa dopočíta okamžite.
 */
class RetirementService
{
    /** Počet simulovaných scenárov. */
    protected const PATHS = 1500;

    /** Dĺžka bloku pri bootstrape (mesiace) — zachováva krátkodobú autokoreláciu trhu. */
    protected const BLOCK = 12;

    /** Počet ciest pre výberovú fázu — menej než pri sporení, beží šesťkrát. */
    protected const WITHDRAWAL_PATHS = 900;

    /** Cieľová úspešnosť, pri ktorej sa miera výberu považuje za bezpečnú. */
    protected const SUCCESS_TARGET = 90.0;

    /** Pevný seed — rovnaké vstupy musia dať vždy rovnaký graf. */
    protected const SEED = 20650101;

    /** Reálny výnos, s ktorým sa počíta, kým nie sú stiahnuté trhové dáta. */
    public const FALLBACK_REAL_RETURN = 5.0;

    /** O koľko rokov za cieľový rok sa ešte hľadá rok slobody. */
    protected const LOOKAHEAD_YEARS = 30;

    /**
     * Predvolený historický rad: najdlhší dostupný. Bootstrap potrebuje
     * skutočné krízy, a len 41-ročný rad má 1987, dot-com, 2008 aj 2022.
     */
    public const DEFAULT_ENGINE = 'us_long';

    /**
     * Predvolená konzervatívna zrážka. So 41-ročným radom (11,3 % ročne)
     * vychádza reálny výnos ~5 % — teda to, čo za 125 rokov a 35 trhov
     * namerali Dimson, Marsh a Staunton pre svetové akcie (5,2 % reálne).
     */
    public const DEFAULT_HAIRCUT = 2.5;

    public function __construct(protected MarketDataService $market) {}

    /**
     * Vstupy plánu — z uloženého nastavenia používateľa, prekryté requestom,
     * s defaultmi odvodenými z historických dát.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function resolveParams(User $user, array $overrides = []): array
    {
        $infl = $this->market->inflation('SK');
        $defaultInflation = $infl['avg20'] ?? 3.0;

        $p = [
            'year' => (int) ($overrides['year'] ?? $user->retire_year ?? 2065),
            // ako dlho má dôchodok trvať — rozhoduje o bezpečnej miere výberu
            'duration' => (int) ($overrides['duration'] ?? $user->retire_duration ?? 35),
            'monthly' => (float) ($overrides['monthly'] ?? $user->retire_monthly ?? 0),
            'index_contributions' => (bool) ($overrides['index_contributions'] ?? $user->retire_index_contributions ?? true),
            'inflation' => (float) ($overrides['inflation'] ?? $user->retire_inflation ?? $defaultInflation),
            'fees' => (float) ($overrides['fees'] ?? $user->retire_fees ?? 0.25),
            'haircut' => (float) ($overrides['haircut'] ?? $user->retire_haircut ?? self::DEFAULT_HAIRCUT),
            'withdrawal' => (float) ($overrides['withdrawal'] ?? $user->retire_withdrawal ?? 4.0),
            'engine' => (string) ($overrides['engine'] ?? $user->retire_engine ?? self::DEFAULT_ENGINE),
            'target_income' => $overrides['target_income'] ?? $user->retire_target_income,
            // mesačné výdavky v dnešných € — z nich vychádza suma potrebná na slobodu
            'spending' => $overrides['spending'] ?? $user->retire_spending,
            // „čo keby som vkladal…" — do profilu sa neukladá
            'compare' => $overrides['compare'] ?? null,
            // jednorazový výdavok, ktorého dopad chceme vidieť
            'spend' => $overrides['spend'] ?? null,
            // opakovaný mesačný výdavok namiesto jednorazového
            'spend_monthly' => (bool) ($overrides['spend_monthly'] ?? false),
        ];

        $p['compare'] = $p['compare'] !== null && $p['compare'] !== '' ? max(0, (float) $p['compare']) : null;
        $p['spend'] = $p['spend'] !== null && $p['spend'] !== '' ? max(0, (float) $p['spend']) : null;

        $p['target_income'] = $p['target_income'] !== null && $p['target_income'] !== '' ? (float) $p['target_income'] : null;
        $p['spending'] = $p['spending'] !== null && $p['spending'] !== '' ? max(0, (float) $p['spending']) : null;
        $p['year'] = max(CarbonImmutable::today()->year + 1, min(2120, $p['year']));
        $p['duration'] = max(5, min(60, $p['duration']));
        $p['inflation'] = max(0, min(20, $p['inflation']));
        $p['fees'] = max(0, min(5, $p['fees']));
        $p['haircut'] = max(0, min(8, $p['haircut']));
        $p['withdrawal'] = max(0.5, min(10, $p['withdrawal']));
        $p['monthly'] = max(0, $p['monthly']);
        if (! isset(MarketDataService::BENCHMARKS[$p['engine']])) {
            $p['engine'] = self::DEFAULT_ENGINE;
        }

        return $p;
    }

    /**
     * Reálny (po inflácii, poplatkoch a zrážke) očakávaný výnos podľa toho, čo
     * má používateľ nastavené v dôchodkovom pláne. Slúži ostatným stránkam,
     * aby všade v appke platil ten istý predpoklad.
     */
    public function realReturnAssumption(User $user): float
    {
        // číta len z cache — inak by každá stránka, ktorá tento predpoklad
        // zobrazuje, čakala na Yahoo a ECB
        $engine = $this->market->cachedBenchmark($user->retire_engine ?? self::DEFAULT_ENGINE);
        if (! $engine) {
            return self::FALLBACK_REAL_RETURN;
        }

        $inflation = $user->retire_inflation !== null
            ? (float) $user->retire_inflation
            : (float) ($this->market->cachedInflation('SK')['avg20'] ?? 3.0);

        $drag = (float) ($user->retire_fees ?? 0.25) + (float) ($user->retire_haircut ?? self::DEFAULT_HAIRCUT);
        $real = ((1 + $engine['cagr']) * (1 - $drag / 100)) / (1 + $inflation / 100) - 1;

        return $real > 0 ? round($real * 100, 2) : 0.0;
    }

    /**
     * Kompletná projekcia.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function project(User $user, float $startValue, array $overrides = []): array
    {
        $p = $this->resolveParams($user, $overrides);
        $engine = $this->market->benchmark($p['engine']);

        if (! $engine) {
            return ['ok' => false, 'reason' => 'Nepodarilo sa stiahnuť historické dáta trhu. Skús to o chvíľu znova.', 'params' => $p];
        }

        $today = CarbonImmutable::today();
        // celé roky od dnes po cieľový rok — posledný bod série padne do roku odchodu
        $months = ($p['year'] - $today->year) * 12;
        if ($months < 12) {
            return ['ok' => false, 'reason' => 'Cieľový rok je príliš blízko na zmysluplnú projekciu.', 'params' => $p];
        }
        $years = intdiv($months, 12);

        // Simuluje sa aj za cieľový rok — keď sa sloboda do neho nestihne,
        // je poctivejšie povedať „vyjde to v roku X" než „nevyjde to".
        $horizon = $years + self::LOOKAHEAD_YEARS;

        $drag = $p['fees'] + $p['haircut'];
        $sim = $this->simulate($engine['returns'], $horizon * 12, $drag, $p['inflation'], $p['index_contributions']);

        $inflFactor = 1 + $p['inflation'] / 100;
        $swr = $p['withdrawal'] / 100;

        // --- séria po rokoch ---
        $series = [];
        for ($y = 0; $y <= $years; $y++) {
            $vals = $this->valuesAt($sim, $y, $startValue, $p['monthly']);
            sort($vals);
            $deflate = $inflFactor ** $y;
            $pct = fn (float $q) => $vals[(int) round($q * (count($vals) - 1))];

            $series[] = [
                'year' => $today->year + $y,
                'contributed' => round($startValue + $p['monthly'] * $sim['contrib'][$y], 2),
                'p10' => round($pct(0.10), 2),
                'p25' => round($pct(0.25), 2),
                'p50' => round($pct(0.50), 2),
                'p75' => round($pct(0.75), 2),
                'p90' => round($pct(0.90), 2),
                'real_p10' => round($pct(0.10) / $deflate, 2),
                'real_p25' => round($pct(0.25) / $deflate, 2),
                'real_p50' => round($pct(0.50) / $deflate, 2),
                'real_p75' => round($pct(0.75) / $deflate, 2),
                'real_p90' => round($pct(0.90) / $deflate, 2),
                'real_contributed' => round(($startValue + $p['monthly'] * $sim['contrib'][$y]) / $deflate, 2),
            ];
        }

        $final = end($series);
        $deflateEnd = $inflFactor ** $years;

        // --- doživotná renta podľa pravidla bezpečného výberu ---
        $income = fn (float $realValue) => $realValue * $swr / 12;

        // --- pravdepodobnosť dosiahnutia cieľa ---
        $success = null;
        $required = null;
        if ($p['target_income'] !== null && $p['target_income'] > 0) {
            $targetRealValue = $p['target_income'] * 12 / $swr;
            $targetNominal = $targetRealValue * $deflateEnd;

            $finalVals = $this->valuesAt($sim, $years, $startValue, $p['monthly']);
            $hits = 0;
            foreach ($finalVals as $v) {
                if ($v >= $targetNominal) {
                    $hits++;
                }
            }
            $success = round($hits / count($finalVals) * 100, 1);
            $required = [
                // vklad, pri ktorom cieľ dosiahne polovica scenárov
                'median' => $this->requiredMonthly($sim, $years, $startValue, $targetNominal, 0.5),
                // vklad, pri ktorom cieľ dosiahne aj 90 % scenárov (opatrný plán)
                'safe' => $this->requiredMonthly($sim, $years, $startValue, $targetNominal, 0.1),
            ];
        }

        return [
            'ok' => true,
            'params' => $p,
            'freedom' => $this->freedom($sim, $p, $startValue, $years, $horizon, $inflFactor, $swr, $today->year),
            'scenarios' => $this->scenarios($sim, $p, $startValue, $years, $inflFactor, $swr, $today->year),
            'withdrawal' => $this->withdrawalPhase($engine['returns'], $p, $startValue, $years, $inflFactor, $today->year),
            'purchase' => $this->purchaseImpact($sim, $p, $startValue, $years, $horizon, $inflFactor, $swr, $today->year),
            'start_value' => round($startValue, 2),
            'years' => $years,
            'months' => $months,
            'series' => $series,
            'final' => [
                'nominal' => ['p10' => $final['p10'], 'p50' => $final['p50'], 'p90' => $final['p90']],
                'real' => ['p10' => $final['real_p10'], 'p50' => $final['real_p50'], 'p90' => $final['real_p90']],
                'contributed' => $final['contributed'],
                'real_contributed' => $final['real_contributed'],
                'growth' => round($final['p50'] - $final['contributed'], 2),
                'income' => [
                    'p10' => round($income($final['real_p10']), 2),
                    'p50' => round($income($final['real_p50']), 2),
                    'p90' => round($income($final['real_p90']), 2),
                ],
                'income_nominal_p50' => round($final['p50'] * $swr / 12, 2),
            ],
            'target' => $p['target_income'] === null ? null : [
                'income' => $p['target_income'],
                'success_pct' => $success,
                'required_monthly' => $required['median'] ?? null,
                'required_monthly_safe' => $required['safe'] ?? null,
                'required_delta' => ($required['median'] ?? null) === null ? null : round($required['median'] - $p['monthly'], 2),
            ],
            'engine' => [
                'key' => $p['engine'],
                'label' => $engine['label'],
                'note' => $engine['note'],
                'currency' => $engine['currency'],
                'from' => $engine['from'],
                'to' => $engine['to'],
                'months' => $engine['months'],
                'years' => $engine['years'],
                'short_history' => $engine['short_history'],
                'cagr' => round($engine['cagr'] * 100, 2),
                'vol' => round($engine['vol'] * 100, 1),
                'worst_month' => round($engine['worst'] * 100, 1),
                'best_month' => round($engine['best'] * 100, 1),
                // výnos po odrátaní poplatkov a konzervatívnej zrážky
                'net_cagr' => round(((1 + $engine['cagr']) * (1 - $drag / 100) - 1) * 100, 2),
                // a ten istý výnos ešte po inflácii — reálne zhodnotenie kúpnej sily
                'real_cagr' => round((((1 + $engine['cagr']) * (1 - $drag / 100)) / $inflFactor - 1) * 100, 2),
                'drag' => round($drag, 2),
            ],
            'inflation' => $this->inflationInfo($p['inflation']),
            'paths' => self::PATHS,
        ];
    }

    /**
     * Obrátená otázka: nie „koľko budem mať v cieľovom roku", ale „v ktorom roku
     * mám dosť na to, aby som nemusel pracovať". Suma potrebná na slobodu
     * (FIRE číslo) = ročné výdavky delené mierou výberu.
     *
     * Coast FIRE je bod, od ktorého už netreba vložiť ani euro — to, čo je
     * nasporené, dorastie do cieľa samo.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>|null
     */
    protected function freedom(array $sim, array $p, float $startValue, int $years, int $horizon, float $inflFactor, float $swr, int $thisYear): ?array
    {
        if ($p['spending'] === null || $p['spending'] <= 0) {
            return null;
        }

        $annual = $p['spending'] * 12;
        $fire = $annual / $swr; // v dnešných eurách

        // Prvý rok, v ktorom daný scenár prekročí potrebnú sumu. Hľadá sa aj
        // za cieľovým rokom — chceme vedieť kedy, nie len či.
        $firstYear = function (float $q) use ($sim, $startValue, $p, $horizon, $inflFactor, $fire, $thisYear): ?int {
            for ($y = 0; $y <= $horizon; $y++) {
                $vals = $this->valuesAt($sim, $y, $startValue, $p['monthly']);
                sort($vals);
                $value = $vals[(int) round($q * (count($vals) - 1))] / ($inflFactor ** $y);
                if ($value >= $fire) {
                    return $thisYear + $y;
                }
            }

            return null;
        };

        // Coast FIRE: od ktorého roku stačí prestať vkladať a nechať to rásť
        $coastYear = null;
        $endFactor = $inflFactor ** $years;
        for ($y = 0; $y <= $years; $y++) {
            $coasted = [];
            foreach ($sim['G'] as $i => $gRow) {
                if ($gRow[$y] <= 0) {
                    continue;
                }
                $valueNow = $startValue * $gRow[$y] + $p['monthly'] * $sim['A'][$i][$y];
                $coasted[] = $valueNow * ($gRow[$years] / $gRow[$y]) / $endFactor;
            }
            if (! $coasted) {
                continue;
            }
            sort($coasted);
            if ($coasted[(int) round(0.5 * (count($coasted) - 1))] >= $fire) {
                $coastYear = $thisYear + $y;
                break;
            }
        }

        $medianYear = $firstYear(0.50);
        $withinPlan = $medianYear !== null && $medianYear <= $p['year'];

        // Ak sa to do cieľového roku nestihne: koľko by musel vkladať, aby áno.
        $required = null;
        if (! $withinPlan) {
            $required = $this->requiredMonthly($sim, $years, $startValue, $fire * ($inflFactor ** $years), 0.50);
        }

        return [
            'monthly_spending' => round($p['spending'], 2),
            'annual_spending' => round($annual, 2),
            'fire_number' => round($fire, 2),
            'progress_pct' => $fire > 0 ? round($startValue / $fire * 100, 1) : 0,
            // rok, kedy na to má polovica scenárov / aj tie zlé / len tie dobré
            'year' => $medianYear,
            'year_safe' => $firstYear(0.10),
            'year_lucky' => $firstYear(0.90),
            'years_from_now' => $medianYear === null ? null : $medianYear - $thisYear,
            // kladné = pred plánovaným odchodom, záporné = po ňom
            'years_earlier' => $medianYear === null ? null : $p['year'] - $medianYear,
            // sedí to do plánovaného roku odchodu?
            'within_plan' => $withinPlan,
            'required_monthly' => $required,
            'required_extra' => $required === null ? null : round($required - $p['monthly'], 2),
            'coast_year' => $coastYear,
            'coast_years_from_now' => $coastYear === null ? null : $coastYear - $thisYear,
            'reached' => $medianYear !== null,
            'horizon_year' => $thisYear + $horizon,
        ];
    }

    /**
     * Výberová fáza — jediná časť, ktorá odpovedá na otázku „vydrží mi to".
     *
     * Kým sa sporí, na poradí výnosov až tak nezáleží; krach na začiatku je
     * dokonca výhodný, lebo vklady nakupujú lacno. Pri výbere je to presne
     * naopak: prepad v prvých rokoch núti predávať na dne a ten kapitál sa
     * už zotavenia nezúčastní. Priemerný výnos preto o prežití portfólia
     * nehovorí takmer nič — rozhoduje poradie.
     *
     * Preto sa tu nesimuluje len dôchodok, ale **súvislá cesta** od dneška
     * cez sporenie až po posledný výber: tá istá náhodná postupnosť trhu,
     * vrátane prechodu medzi fázami.
     *
     * @param  array<int, float>  $hist
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>|null
     */
    protected function withdrawalPhase(array $hist, array $p, float $startValue, int $accumYears, float $inflFactor, int $thisYear): ?array
    {
        if ($p['spending'] === null || $p['spending'] <= 0) {
            return null;
        }

        $duration = $p['duration'];
        $annual = $p['spending'] * 12;

        // 1) Prežije to, čo si nasporíš podľa vlastného plánu?
        $plan = $this->runPaths($hist, $p, $startValue, $accumYears, $duration, $annual / 12, $inflFactor);

        // 2) Aká miera výberu je pri tvojej dĺžke dôchodku ešte bezpečná?
        //    Tu sa štartuje z presne nasporenej sumy, aby šlo o vlastnosť
        //    samotného pravidla, nie tvojho tempa vkladov.
        $rates = [];
        foreach ([4.0, 3.75, 3.5, 3.25, 3.0, 2.75] as $rate) {
            $fire = $annual / ($rate / 100);
            $r = $this->runPaths($hist, $p, $fire, 0, $duration, $annual / 12, $inflFactor);
            $rates[] = [
                'rate' => $rate,
                'needed' => round($fire, 2),
                'success_pct' => $r['success_pct'],
            ];
        }

        // najnižšia suma, ktorá ešte drží cieľovú úspešnosť
        $safe = null;
        foreach ($rates as $r) {
            if ($r['success_pct'] >= self::SUCCESS_TARGET) {
                $safe = $r;
                break;
            }
        }

        return [
            'duration' => $duration,
            'annual_withdrawal' => round($annual, 2),
            'success_pct' => $plan['success_pct'],
            'depleted_year' => $plan['depleted_median'] === null ? null : $thisYear + $accumYears + $plan['depleted_median'],
            'depleted_after_years' => $plan['depleted_median'],
            'median_left' => $plan['median_left'],
            'rates' => $rates,
            'safe_rate' => $safe,
            'target' => self::SUCCESS_TARGET,
            'current_rate' => $p['withdrawal'],
        ];
    }

    /**
     * Spustí cesty: najprv sporenie (ak nejaké je), potom výber, na tej istej
     * náhodnej postupnosti. Vracia podiel ciest, ktoré vydržali celý dôchodok.
     *
     * @param  array<int, float>  $hist
     * @param  array<string, mixed>  $p
     * @return array{success_pct: float, depleted_median: ?int, median_left: float}
     */
    protected function runPaths(array $hist, array $p, float $startValue, int $accumYears, int $duration, float $withdrawMonthly, float $inflFactor): array
    {
        $n = count($hist);
        $feeFactor = (1 - ($p['fees'] + $p['haircut']) / 100) ** (1 / 12);
        $inflMonthly = $inflFactor ** (1 / 12);
        $contribIndexed = $p['index_contributions'];

        $accumMonths = $accumYears * 12;
        $totalMonths = $accumMonths + $duration * 12;

        $survived = 0;
        $depleted = [];
        $left = [];

        for ($path = 0; $path < self::WITHDRAWAL_PATHS; $path++) {
            // pevný seed na cestu — rovnaké vstupy dajú vždy rovnaký výsledok
            mt_srand(self::SEED + $path);

            $v = $startValue;
            $contrib = $p['monthly'];
            $withdraw = $withdrawMonthly;
            $blockPos = self::BLOCK;
            $cursor = 0;
            $diedAt = null;

            for ($m = 0; $m < $totalMonths; $m++) {
                if ($blockPos >= self::BLOCK) {
                    $cursor = mt_rand(0, $n - 1);
                    $blockPos = 0;
                }
                $f = (1 + $hist[($cursor + $blockPos) % $n]) * $feeFactor;
                $blockPos++;
                if ($f < 0) {
                    $f = 0.0;
                }

                if ($m < $accumMonths) {
                    $v = ($v + $contrib) * $f;
                    if ($contribIndexed) {
                        $contrib *= $inflMonthly;
                    }
                } else {
                    // výber rastie s infláciou — kúpna sila zostáva rovnaká
                    $v = ($v - $withdraw) * $f;
                    $withdraw *= $inflMonthly;
                    if ($v <= 0) {
                        $diedAt = intdiv($m - $accumMonths, 12);
                        break;
                    }
                }
            }

            if ($diedAt === null) {
                $survived++;
                // zostatok v dnešných eurách
                $left[] = $v / ($inflFactor ** ($accumYears + $duration));
            } else {
                $depleted[] = $diedAt;
            }
        }

        mt_srand();
        sort($left);
        sort($depleted);

        return [
            'success_pct' => round($survived / self::WITHDRAWAL_PATHS * 100, 1),
            'depleted_median' => $depleted ? $depleted[intdiv(count($depleted), 2)] : null,
            'median_left' => $left ? round($left[intdiv(count($left), 2)], 2) : 0.0,
        ];
    }

    /**
     * „Čo keby som vkladal viac." Simulácia je v mesačnom vklade lineárna
     * (V = štart · G + vklad · A), takže ďalšia úroveň nestojí novú simuláciu —
     * len prepočet už hotových scenárov.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    protected function scenarios(array $sim, array $p, float $startValue, int $years, float $inflFactor, float $swr, int $thisYear): array
    {
        $fire = $p['spending'] !== null && $p['spending'] > 0 ? $p['spending'] * 12 / $swr : null;
        $base = $this->scenario($sim, $startValue, $years, $inflFactor, $swr, $p['monthly'], $fire, $thisYear);

        // pár okrúhlych krokov nad súčasným tempom — nech je vidieť páku
        $steps = [];
        foreach ([50, 100, 200, 400] as $extra) {
            $steps[] = $this->scenario($sim, $startValue, $years, $inflFactor, $swr, $p['monthly'] + $extra, $fire, $thisYear)
                + ['extra' => $extra];
        }

        $custom = null;
        if ($p['compare'] !== null) {
            $custom = $this->scenario($sim, $startValue, $years, $inflFactor, $swr, $p['compare'], $fire, $thisYear)
                + ['extra' => round($p['compare'] - $p['monthly'], 2)];
        }

        return [
            'base' => $base,
            'ladder' => $steps,
            'custom' => $custom,
            'fire_number' => $fire === null ? null : round($fire, 2),
        ];
    }

    /**
     * Jeden scenár pri danom mesačnom vklade.
     *
     * @return array<string, mixed>
     */
    protected function scenario(array $sim, float $startValue, int $years, float $inflFactor, float $swr, float $monthly, ?float $fire, int $thisYear): array
    {
        $final = $this->valuesAt($sim, $years, $startValue, $monthly);
        sort($final);
        $pct = fn (float $q) => $final[(int) round($q * (count($final) - 1))];

        $deflate = $inflFactor ** $years;
        $realP50 = $pct(0.50) / $deflate;

        // v ktorom roku by pri tomto tempe medián pokryl sumu potrebnú na slobodu
        $freedomYear = null;
        if ($fire !== null && $fire > 0) {
            for ($y = 0; $y <= $years; $y++) {
                $vals = $this->valuesAt($sim, $y, $startValue, $monthly);
                sort($vals);
                $median = $vals[(int) round(0.5 * (count($vals) - 1))];
                if ($median / ($inflFactor ** $y) >= $fire) {
                    $freedomYear = $thisYear + $y;
                    break;
                }
            }
        }

        return [
            'monthly' => round($monthly, 2),
            'nominal_p50' => round($pct(0.50), 2),
            'real_p10' => round($pct(0.10) / $deflate, 2),
            'real_p50' => round($realP50, 2),
            'real_p90' => round($pct(0.90) / $deflate, 2),
            'income_p50' => round($realP50 * $swr / 12, 2),
            'contributed' => round($startValue + $monthly * $sim['contrib'][$years], 2),
            'freedom_year' => $freedomYear,
        ];
    }

    /**
     * Čo by z jedného výdavku bolo, keby namiesto neho išiel do portfólia —
     * a o koľko neskôr kvôli nemu príde sloboda.
     *
     * Beží nad tou istou simuláciou ako zvyšok stránky: jednorazová suma len
     * zníži počiatočnú hodnotu, opakovaná zníži mesačný vklad. Preto to nestojí
     * nový výpočet a odpoveď je exaktná, nie odhad.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>|null
     */
    protected function purchaseImpact(array $sim, array $p, float $startValue, int $years, int $horizon, float $inflFactor, float $swr, int $thisYear): ?array
    {
        if ($p['spend'] === null || $p['spend'] <= 0) {
            return null;
        }

        $amount = $p['spend'];
        $recurring = $p['spend_monthly'];

        // varianta bez nákupu vs. s ním
        $altStart = $recurring ? $startValue : max(0, $startValue - $amount);
        $altMonthly = $recurring ? max(0, $p['monthly'] - $amount) : $p['monthly'];

        $medianAt = function (int $y, float $start, float $monthly) use ($sim, $inflFactor) {
            $vals = $this->valuesAt($sim, $y, $start, $monthly);
            sort($vals);

            return $vals[(int) round(0.5 * (count($vals) - 1))] / ($inflFactor ** $y);
        };

        // Čím by tá suma bola po N rokoch — v dnešných eurách.
        //
        // Rastie priamo tá suma, nie rozdiel dvoch mediánov celého portfólia:
        // tie sa zoraďujú zvlášť, takže ich rozdiel nie je násobkom sumy
        // a to isté euro by pri inej cene vyšlo na inú hodnotu.
        $factorKey = $recurring ? 'A' : 'G';
        $horizons = [];
        foreach ([5, 10, 20, $years] as $y) {
            if ($y > $years || ($horizons && end($horizons)['years'] === $y)) {
                continue;
            }
            $factors = array_map(fn ($row) => $row[$y], $sim[$factorKey]);
            sort($factors);
            $median = $factors[(int) round(0.5 * (count($factors) - 1))];

            $horizons[] = [
                'years' => $y,
                'year' => $thisYear + $y,
                'value' => round($amount * $median / ($inflFactor ** $y), 2),
            ];
        }

        // o koľko sa posunie rok slobody
        $freedomYear = function (float $start, float $monthly) use ($p, $horizon, $swr, $thisYear, $medianAt): ?int {
            if ($p['spending'] === null || $p['spending'] <= 0) {
                return null;
            }
            $fire = $p['spending'] * 12 / $swr;
            for ($y = 0; $y <= $horizon; $y++) {
                if ($medianAt($y, $start, $monthly) >= $fire) {
                    return $thisYear + $y;
                }
            }

            return null;
        };

        $keep = $freedomYear($startValue, $p['monthly']);
        $spend = $freedomYear($altStart, $altMonthly);

        return [
            'amount' => round($amount, 2),
            'recurring' => $recurring,
            'horizons' => $horizons,
            'freedom_if_saved' => $keep,
            'freedom_if_spent' => $spend,
            'delay_years' => $keep !== null && $spend !== null ? $spend - $keep : null,
        ];
    }

    /** Kontext k použitej inflácii (historické priemery z ECB). */
    protected function inflationInfo(float $used): array
    {
        $i = $this->market->inflation('SK');
        $eu = $this->market->inflation('U2');

        return [
            'used' => $used,
            'sk_avg' => $i['avg'] ?? null,
            'sk_avg20' => $i['avg20'] ?? null,
            'sk_latest' => $i['latest'] ?? null,
            'sk_from' => $i['from'] ?? null,
            'sk_to' => $i['to'] ?? null,
            'eu_avg20' => $eu['avg20'] ?? null,
            // koľko je dnešných 1 000 € v cieľovom roku (ilustrácia sily inflácie)
            'source' => 'ECB Data Portal — HICP',
        ];
    }

    /**
     * Block bootstrap simulácia. Pre každý scenár a každý celý rok si pamätá
     * rastový faktor G (pre počiatočnú sumu) a anuitný faktor A (pre vklad 1 €).
     *
     * @param  array<int, float>  $hist
     * @return array{G: array<int, array<int, float>>, A: array<int, array<int, float>>, contrib: array<int, float>, years: int}
     */
    protected function simulate(array $hist, int $months, float $dragPct, float $inflationPct, bool $indexed): array
    {
        $n = count($hist);
        $years = intdiv($months, 12);
        $feeFactor = (1 - $dragPct / 100) ** (1 / 12);
        $inflMonthly = $indexed ? (1 + $inflationPct / 100) ** (1 / 12) : 1.0;

        // kumulatívny nominálny vklad (v násobkoch mesačného vkladu) po rokoch
        $contrib = [0.0];
        $idx = 1.0;
        $sum = 0.0;
        for ($m = 0; $m < $months; $m++) {
            $sum += $idx;
            $idx *= $inflMonthly;
            if (($m + 1) % 12 === 0) {
                $contrib[] = $sum;
            }
        }

        $G = [];
        $A = [];
        // deterministický seed → rovnaké vstupy dajú rovnaký graf (žiadne blikanie pri prepočte)
        mt_srand(self::SEED);

        for ($path = 0; $path < self::PATHS; $path++) {
            $g = 1.0;
            $a = 0.0;
            $idx = 1.0;
            $gRow = [1.0];
            $aRow = [0.0];

            $blockPos = self::BLOCK; // vynúti výber nového bloku hneď v prvom mesiaci
            $cursor = 0;

            for ($m = 0; $m < $months; $m++) {
                if ($blockPos >= self::BLOCK) {
                    $cursor = mt_rand(0, $n - 1);
                    $blockPos = 0;
                }
                $r = $hist[($cursor + $blockPos) % $n];
                $blockPos++;

                $f = (1 + $r) * $feeFactor;
                if ($f < 0) {
                    $f = 0.0;
                }

                // vklad na začiatku mesiaca, potom trhový pohyb
                $a = $a * $f + $idx * $f;
                $g *= $f;
                $idx *= $inflMonthly;

                if (($m + 1) % 12 === 0) {
                    $gRow[] = $g;
                    $aRow[] = $a;
                }
            }

            $G[] = $gRow;
            $A[] = $aRow;
        }

        mt_srand();

        return ['G' => $G, 'A' => $A, 'contrib' => $contrib, 'years' => $years];
    }

    /**
     * Hodnoty všetkých scenárov v roku $y pri danom mesačnom vklade.
     *
     * @return array<int, float>
     */
    protected function valuesAt(array $sim, int $y, float $start, float $monthly): array
    {
        $out = [];
        foreach ($sim['G'] as $i => $gRow) {
            $out[] = $start * $gRow[$y] + $monthly * $sim['A'][$i][$y];
        }

        return $out;
    }

    /**
     * Aký mesačný vklad treba, aby daný kvantil scenárov dosiahol cieľovú sumu.
     * $q = 0.5 → medián („vyjde to na polovicu"), $q = 0.1 → opatrný plán.
     */
    protected function requiredMonthly(array $sim, int $y, float $start, float $targetNominal, float $q = 0.5): ?float
    {
        $quantile = function (float $c) use ($sim, $y, $start, $q) {
            $v = $this->valuesAt($sim, $y, $start, $c);
            sort($v);

            return $v[(int) round($q * (count($v) - 1))];
        };

        if ($quantile(0) >= $targetNominal) {
            return 0.0;
        }

        $lo = 0.0;
        $hi = 100.0;
        $guard = 0;
        while ($quantile($hi) < $targetNominal && $guard++ < 40) {
            $hi *= 2;
        }
        if ($guard >= 40) {
            return null;
        }

        for ($i = 0; $i < 50; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($quantile($mid) < $targetNominal) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        // zaokrúhľuje sa nahor — o cent nižšia suma by cieľ tesne minula
        return ceil($hi * 100) / 100;
    }

    /**
     * Cache-ovaný wrapper — simulácia je deterministická, takže rovnaké vstupy
     * môžu vrátiť uložený výsledok.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function cachedProject(User $user, float $startValue, array $overrides = []): array
    {
        $p = $this->resolveParams($user, $overrides);
        $key = 'retirement:'.$user->id.':'.md5(json_encode($p).':'.round($startValue, 2).':'.CarbonImmutable::today()->format('Y-m-d'));

        return Cache::remember($key, now()->addHours(6), fn () => $this->project($user, $startValue, $overrides));
    }
}
