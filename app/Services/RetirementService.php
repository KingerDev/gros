<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Dôchodková projekcia v troch scenároch — v dnešných eurách.
 *
 * Zámerne žiadna simulácia trhu: o výsledku na 30–40 rokov rozhoduje takmer
 * výlučne predpoklad výnosu, a ten nevie nikto. Tri čitateľné predpoklady
 * (3 / 5 / 7 % ročne po inflácii aj poplatkoch) povedia to isté poctivejšie
 * než tisíc scenárov postavených na jednom z nich. Stredný scenár zodpovedá
 * dlhodobému reálnemu výnosu svetových akcií (Dimson, Marsh, Staunton:
 * ~5 % za vyše 120 rokov), krajné ukazujú, ako veľmi na ňom všetko visí.
 *
 * Všetko je reálne — vklad aj výdavky sa berú v dnešných eurách, teda ako
 * keby rástli s infláciou. So štátnym dôchodkom sa zámerne nepočíta: na
 * desaťročia dopredu je to najmenej istá časť plánu.
 */
class RetirementService
{
    /** Reálne ročné výnosy scenárov (%, po inflácii aj poplatkoch). */
    public const SCENARIOS = [
        'low' => ['label' => 'Opatrný', 'return' => 3.0],
        'mid' => ['label' => 'Stredný', 'return' => 5.0],
        'high' => ['label' => 'Priaznivý', 'return' => 7.0],
    ];

    /**
     * Dlhodobá inflácia (cieľ ECB). Treba ju len tam, kde sa reálny výnos
     * porovnáva s nominálnou sadzbou — napríklad s úrokom úveru.
     */
    public const LONG_RUN_INFLATION = 2.0;

    /** O koľko rokov za plánovaný odchod sa ešte hľadá rok slobody. */
    protected const LOOKAHEAD_YEARS = 30;

    /**
     * Ako sa počíta s kryptom: nerátať, držať dnešnú hodnotu (v dnešných
     * eurách), alebo ako s akciami. Na krypto neexistuje dlhodobý výnos, ktorý
     * by sa dal poctivo predpokladať — preto predvolene nerátať.
     */
    public const CRYPTO_MODES = ['exclude', 'flat', 'stocks'];

    /** Do koľkých rokov veku má portfólio vydržať — radšej dlhšie než kratšie. */
    public const PLAN_TO_AGE = 90;

    /**
     * Vstupy plánu — z uloženého nastavenia používateľa, prekryté requestom.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function resolveParams(User $user, array $overrides = []): array
    {
        $thisYear = CarbonImmutable::today()->year;
        $p = [
            'year' => (int) ($overrides['year'] ?? $user->retire_year ?? 2065),
            'birth_year' => $overrides['birth_year'] ?? $user->retire_birth_year,
            'monthly' => (float) ($overrides['monthly'] ?? $user->retire_monthly ?? 0),
            // druhá fáza vkladu — napríklad od roku, keď sa po škole zmení príjem
            'monthly_later' => $overrides['monthly_later'] ?? null,
            'later_from' => $overrides['later_from'] ?? null,
            // prázdna = odporúčaná podľa dĺžky dôchodku
            'withdrawal' => $overrides['withdrawal'] ?? $user->retire_withdrawal,
            // mesačné výdavky v dnešných € — z nich vychádza suma potrebná na slobodu
            'spending' => $overrides['spending'] ?? $user->retire_spending,
            // koľko zo štartovacej sumy je krypto a ako sa s ním počíta
            'crypto_value' => (float) ($overrides['crypto_value'] ?? 0),
            'crypto_mode' => $overrides['crypto_mode'] ?? $user->retire_crypto_mode,
        ];
        $p['crypto_mode'] = in_array($p['crypto_mode'], self::CRYPTO_MODES, true) ? $p['crypto_mode'] : 'exclude';

        $p['spending'] = $p['spending'] !== null && $p['spending'] !== '' ? max(0, (float) $p['spending']) : null;
        $p['year'] = max($thisYear + 1, min(2120, $p['year']));
        $p['birth_year'] = $p['birth_year'] !== null && $p['birth_year'] !== '' ? (int) $p['birth_year'] : null;
        $p['monthly'] = max(0, $p['monthly']);

        if ($p['monthly_later'] === null || $p['monthly_later'] === '' || $p['later_from'] === null || $p['later_from'] === '') {
            $p['monthly_later'] = null;
            $p['later_from'] = null;
        } else {
            $p['monthly_later'] = max(0, (float) $p['monthly_later']);
            $p['later_from'] = max($thisYear, min($p['year'], (int) $p['later_from']));
        }

        $p['withdrawal_recommended'] = $this->recommendedWithdrawal($p['birth_year'], $p['year']);
        $p['withdrawal_is_recommended'] = $p['withdrawal'] === null || $p['withdrawal'] === '';
        $p['withdrawal'] = $p['withdrawal_is_recommended']
            ? $p['withdrawal_recommended']['rate']
            : max(0.5, min(10, (float) $p['withdrawal']));

        return $p;
    }

    /**
     * Odporúčaná miera výberu podľa toho, ako dlho má dôchodok trvať.
     *
     * Pravidlo 4 % pochádza zo štúdií na 30-ročný dôchodok (Bengen 1994,
     * Trinity 1998). Pri dlhšom dôchodku musí portfólio vydržať viac zlých
     * rokov, preto sa bežne odporúča 3–3,5 %. Plánuje sa do 90 rokov veku.
     *
     * @return array{rate: float, duration: ?int, age: ?int}
     */
    public function recommendedWithdrawal(?int $birthYear, int $retireYear): array
    {
        if ($birthYear === null) {
            return ['rate' => 4.0, 'duration' => null, 'age' => null];
        }

        $age = $retireYear - $birthYear;
        $duration = max(0, self::PLAN_TO_AGE - $age);
        $rate = match (true) {
            $duration > 40 => 3.25,
            $duration > 30 => 3.5,
            default => 4.0,
        };

        return ['rate' => $rate, 'duration' => $duration, 'age' => $age];
    }

    /**
     * Reálny výnos, s ktorým počítajú ostatné stránky — stredný scenár, aby
     * všade v appke platil ten istý predpoklad.
     */
    public function realReturnAssumption(?User $user = null): float
    {
        return self::SCENARIOS['mid']['return'];
    }

    /** Ten istý predpoklad nominálne — na porovnanie s úrokom úveru. */
    public function expectedNominalReturn(): float
    {
        $real = self::SCENARIOS['mid']['return'] / 100;

        return round(((1 + $real) * (1 + self::LONG_RUN_INFLATION / 100) - 1) * 100, 2);
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
        $thisYear = CarbonImmutable::today()->year;
        $years = $p['year'] - $thisYear;
        $horizon = $years + self::LOOKAHEAD_YEARS;
        $swr = $p['withdrawal'] / 100;

        // krypto sa buď zhodnocuje so zvyškom (stocks), alebo drží dnešnú
        // hodnotu (flat — pripočíta sa ku každému roku), alebo sa neráta
        $crypto = min(max(0, $p['crypto_value']), $startValue);
        $core = $p['crypto_mode'] === 'stocks' ? $startValue : $startValue - $crypto;
        $flat = $p['crypto_mode'] === 'flat' ? $crypto : 0.0;
        $counted = $core + $flat;

        $fire = $this->fireNumber($p);
        // časť cieľa pokryje krypto s pevnou hodnotou; zvyšok musí dorásť
        $target = $fire === null ? null : max(0, $fire - $flat);

        $series = [];
        for ($y = 0; $y <= $years; $y++) {
            $row = ['year' => $thisYear + $y, 'contributed' => round($this->contributedAt($counted, $p, $y), 2)];
            foreach (self::SCENARIOS as $key => $s) {
                $row[$key] = round($this->valueAtPlan($core, $p, $s['return'], $y) + $flat, 2);
            }
            $series[] = $row;
        }

        $scenarios = [];
        foreach (self::SCENARIOS as $key => $s) {
            $final = $this->valueAtPlan($core, $p, $s['return'], $years) + $flat;
            $scenarios[] = [
                'key' => $key,
                'label' => $s['label'],
                'real_return' => $s['return'],
                'final' => round($final, 2),
                'income' => round($final * $swr / 12, 2),
                'freedom_year' => $target === null ? null : $this->freedomYear($core, $p, $s['return'], $target, $horizon, $thisYear),
            ];
        }
        $byKey = collect($scenarios)->keyBy('key');

        $freedom = null;
        if ($fire !== null) {
            $mid = $byKey['mid']['freedom_year'];
            $withinPlan = $mid !== null && $mid <= $p['year'];
            // Pri dvoch fázach sa dorovnáva tá neskoršia — dnešný vklad z brigády
            // je daný, rozhodnutie o budúcom ešte nie.
            $offset = $this->laterOffset($p);
            $requiredPhase = $offset !== null && $offset < $years ? 'later' : 'now';
            $required = null;
            if (! $withinPlan) {
                $required = $requiredPhase === 'later'
                    ? $this->requiredMonthly($this->valueAt($core, $p['monthly'], self::SCENARIOS['mid']['return'], $offset), self::SCENARIOS['mid']['return'], $target, $years - $offset)
                    : $this->requiredMonthly($core, self::SCENARIOS['mid']['return'], $target, $years);
            }
            $phaseMonthly = $requiredPhase === 'later' ? $p['monthly_later'] : $p['monthly'];

            $freedom = [
                'monthly_spending' => round($p['spending'], 2),
                'fire_number' => round($fire, 2),
                'progress_pct' => $fire > 0 ? round($counted / $fire * 100, 1) : 100.0,
                // rozpätie: priaznivý scenár → opatrný
                'earliest' => $byKey['high']['freedom_year'],
                'year' => $mid,
                'latest' => $byKey['low']['freedom_year'],
                'within_plan' => $withinPlan,
                'required_monthly' => $required,
                'required_extra' => $required === null ? null : round($required - $phaseMonthly, 2),
                // „od roku X treba vkladať…" pri dvoch fázach
                'required_from' => $requiredPhase === 'later' ? $p['later_from'] : null,
                'horizon_year' => $thisYear + $horizon,
            ];
        }

        return [
            'ok' => true,
            'params' => $p,
            'start_value' => round($counted, 2),
            'crypto' => [
                'value' => round($crypto, 2),
                'mode' => $p['crypto_mode'],
                'counted' => round($p['crypto_mode'] === 'exclude' ? 0 : $crypto, 2),
            ],
            'years' => $years,
            'series' => $series,
            'scenarios' => $scenarios,
            'contributed' => round($this->contributedAt($counted, $p, $years), 2),
            'freedom' => $freedom,
        ];
    }

    /**
     * Suma potrebná na slobodu v dnešných eurách = ročné výdavky delené
     * mierou výberu.
     *
     * @param  array<string, mixed>  $p
     */
    public function fireNumber(array $p): ?float
    {
        if ($p['spending'] === null || $p['spending'] <= 0) {
            return null;
        }

        return $p['spending'] * 12 / ($p['withdrawal'] / 100);
    }

    /** Za koľko rokov od dneška začína druhá fáza vkladu; null, ak žiadna nie je. */
    protected function laterOffset(array $p): ?int
    {
        return $p['later_from'] === null ? null : max(0, $p['later_from'] - CarbonImmutable::today()->year);
    }

    /** Hodnota po $years rokoch podľa plánu — prvá fáza vkladu, potom druhá. */
    public function valueAtPlan(float $start, array $p, float $realReturnPct, int $years): float
    {
        $offset = $this->laterOffset($p);
        if ($offset === null || $years <= $offset) {
            return $this->valueAt($start, $p['monthly'], $realReturnPct, $years);
        }

        $atSwitch = $this->valueAt($start, $p['monthly'], $realReturnPct, $offset);

        return $this->valueAt($atSwitch, $p['monthly_later'], $realReturnPct, $years - $offset);
    }

    /** Koľko sa do roku $years vloží, bez zhodnotenia. */
    protected function contributedAt(float $start, array $p, int $years): float
    {
        $offset = $this->laterOffset($p);
        if ($offset === null || $years <= $offset) {
            return $start + $p['monthly'] * 12 * $years;
        }

        return $start + $p['monthly'] * 12 * $offset + $p['monthly_later'] * 12 * ($years - $offset);
    }

    /**
     * Hodnota po $years rokoch pri mesačnom vklade na začiatku mesiaca.
     * Všetko reálne, takže vklad je v dnešných eurách konštantný.
     */
    public function valueAt(float $start, float $monthly, float $realReturnPct, int $years): float
    {
        $n = $years * 12;
        $m = $this->monthlyRate($realReturnPct);
        if ($n <= 0) {
            return $start;
        }
        if (abs($m) < 1e-12) {
            return $start + $monthly * $n;
        }
        $growth = (1 + $m) ** $n;

        return $start * $growth + $monthly * ($growth - 1) / $m * (1 + $m);
    }

    /** Prvý rok, v ktorom hodnota dosiahne cieľ; null, ak ani v horizonte. */
    protected function freedomYear(float $start, array $p, float $realReturnPct, float $fire, int $horizon, int $thisYear): ?int
    {
        for ($y = 0; $y <= $horizon; $y++) {
            if ($this->valueAtPlan($start, $p, $realReturnPct, $y) >= $fire) {
                return $thisYear + $y;
            }
        }

        return null;
    }

    /** Aký mesačný vklad treba, aby hodnota dosiahla cieľ za $years rokov. */
    public function requiredMonthly(float $start, float $realReturnPct, float $target, int $years): ?float
    {
        $n = $years * 12;
        if ($n <= 0) {
            return null;
        }
        $m = $this->monthlyRate($realReturnPct);
        $growth = (1 + $m) ** $n;
        $annuity = abs($m) < 1e-12 ? $n : ($growth - 1) / $m * (1 + $m);
        $missing = $target - $start * $growth;

        // zaokrúhľuje sa nahor — o cent nižšia suma by cieľ tesne minula
        return $missing <= 0 ? 0.0 : ceil($missing / $annuity * 100) / 100;
    }

    protected function monthlyRate(float $annualPct): float
    {
        return (1 + $annualPct / 100) ** (1 / 12) - 1;
    }
}
