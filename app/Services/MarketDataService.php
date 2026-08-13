<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Historické trhové dáta pre projekcie a porovnania:
 *  - mesačné uzávierky benchmarkov (Yahoo Finance)
 *  - história inflácie (ECB Data Portal, HICP — medziročná zmena)
 *
 * Všetko sa cache-uje na 24 h, ide o pomaly sa meniace dáta.
 */
class MarketDataService
{
    /**
     * Ponuka „motorov" pre simuláciu. Zámerne indexy s dlhou históriou —
     * čím dlhšia história, tým reprezentatívnejší bootstrap.
     *
     * @var array<string, array{symbol: string, label: string, note: string}>
     */
    public const BENCHMARKS = [
        'us_long' => [
            'symbol' => 'VFINX',
            'label' => 'S&P 500 — najdlhšia história',
            'since' => 1985,
            'note' => 'Vanguard 500 Index vrátane reinvestovaných dividend, od roku 1985. Najdlhšie okno, aké sa dá získať — obsahuje krach 1987, dot-com, krízu 2008, covid aj rok 2022. Americké akcie v dolároch.',
        ],
        'sp500tr' => [
            'symbol' => '^SP500TR',
            'label' => 'S&P 500 (Total Return index)',
            'since' => 1988,
            'note' => 'Čistý indexový rad amerických akcií vrátane dividend, od 1988. Bez nákladov fondu, zato o tri roky kratší než rad vyššie.',
        ],
        'acwi' => [
            'symbol' => 'ACWI',
            'label' => 'MSCI ACWI (celý svet)',
            'since' => 2008,
            'note' => 'Rozvinuté aj rozvíjajúce sa trhy vrátane dividend, od 2008. Najširší záber, ale história siaha len po finančnú krízu.',
        ],
        'world' => [
            'symbol' => 'IWDA.AS',
            'label' => 'MSCI World (iShares IWDA)',
            'since' => 2009,
            'note' => 'Globálne akcie rozvinutých trhov priamo v EUR, akumulačné ETF. Bez menového prepočtu, ale história od 2009 pokrýva takmer výhradne rastové obdobie — výnos preto vychádza nezvyčajne vysoko.',
        ],
    ];

    /** Pod týmto počtom rokov je história na 40-ročnú projekciu tenká. */
    public const SHORT_HISTORY_YEARS = 25;

    /**
     * Mesačné výnosy benchmarku (nominálne, v mene indexu).
     *
     * @return array{returns: array<int, float>, months: int, from: ?string, to: ?string, currency: string, label: string, note: string, cagr: float, vol: float, worst: float, best: float}|null
     */
    public function benchmark(string $key): ?array
    {
        $def = self::BENCHMARKS[$key] ?? null;
        if (! $def) {
            return null;
        }

        // v2 = od prechodu na adjclose; starý kľúč držal ceny bez dividend
        return Cache::remember("market:benchmark:v2:$key", now()->addHours(24), function () use ($def) {
            [$closes, $currency] = $this->yahooMonthly($def['symbol']);
            if (count($closes) < 60) {
                return null;
            }

            $months = array_keys($closes);
            $values = array_values($closes);
            $returns = [];
            for ($i = 1; $i < count($values); $i++) {
                if ($values[$i - 1] > 0) {
                    $returns[] = $values[$i] / $values[$i - 1] - 1;
                }
            }

            return [
                'returns' => $returns,
                'months' => count($returns),
                'years' => round(count($returns) / 12, 1),
                'short_history' => count($returns) / 12 < self::SHORT_HISTORY_YEARS,
                'from' => $months[0] ?? null,
                'to' => end($months) ?: null,
                'currency' => $currency,
                'label' => $def['label'],
                'note' => $def['note'],
                'cagr' => $this->cagr($returns),
                'vol' => $this->volatility($returns),
                'worst' => $returns ? min($returns) : 0.0,
                'best' => $returns ? max($returns) : 0.0,
            ];
        });
    }

    /**
     * Iba to, čo už je v cache — nikdy nesiahne na sieť. Pre stránky, ktoré
     * predpoklad výnosu len mimochodom zobrazujú a nesmú kvôli nemu čakať.
     *
     * @return array<string, mixed>|null
     */
    public function cachedBenchmark(string $key): ?array
    {
        return Cache::get("market:benchmark:v2:$key");
    }

    /** @return array<string, mixed>|null */
    public function cachedInflation(string $area = 'SK'): ?array
    {
        return Cache::get("market:hicp:$area");
    }

    /**
     * História inflácie (HICP, medziročná zmena v %) z ECB Data Portal.
     * `SK` = Slovensko, `U2` = eurozóna.
     *
     * @return array{area: string, avg: float, avg20: float, latest: ?float, from: ?string, to: ?string, series: array<string, float>}|null
     */
    public function inflation(string $area = 'SK'): ?array
    {
        $area = in_array($area, ['SK', 'U2'], true) ? $area : 'SK';

        return Cache::remember("market:hicp:$area", now()->addHours(24), function () use ($area) {
            try {
                $res = Http::timeout(20)->get("https://data-api.ecb.europa.eu/service/data/ICP/M.$area.N.000000.4.ANR", [
                    'format' => 'jsondata',
                ]);
                if (! $res->ok()) {
                    return null;
                }
                $json = $res->json();

                $periods = data_get($json, 'structure.dimensions.observation.0.values', []);
                $series = data_get($json, 'dataSets.0.series');
                $obs = is_array($series) ? data_get(reset($series), 'observations', []) : [];
                if (! $periods || ! $obs) {
                    return null;
                }

                $byMonth = [];
                foreach ($obs as $idx => $vals) {
                    $ym = data_get($periods, (int) $idx.'.id');
                    $v = $vals[0] ?? null;
                    if ($ym !== null && $v !== null) {
                        $byMonth[$ym] = (float) $v;
                    }
                }
                if (! $byMonth) {
                    return null;
                }
                ksort($byMonth);

                $all = array_values($byMonth);
                $last240 = array_slice($all, -240);

                return [
                    'area' => $area,
                    'avg' => round(array_sum($all) / count($all), 2),
                    'avg20' => round(array_sum($last240) / count($last240), 2),
                    'latest' => end($all) ?: null,
                    'from' => array_key_first($byMonth),
                    'to' => array_key_last($byMonth),
                    'series' => $byMonth,
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Mesačné uzávierky benchmarku prepočítané do EUR — na porovnanie
     * s reálnym portfóliom (kratšie obdobie, FX kurzy sú dostupné).
     *
     * @return array<string, float> ym → cena v EUR
     */
    public function monthlyClosesEur(string $symbol, CarbonImmutable $from): array
    {
        return Cache::remember('market:eur:'.md5($symbol.$from->format('Y-m')), now()->addHours(24), function () use ($symbol, $from) {
            [$closes, $currency] = $this->yahooMonthly($symbol, $from);
            if (! $closes || $currency === 'EUR') {
                return $closes;
            }

            $fx = $this->fxMonthly($currency, $from);
            $out = [];
            $lastRate = null;
            foreach ($closes as $ym => $close) {
                $lastRate = $fx[$ym] ?? $lastRate;
                if ($lastRate) {
                    $out[$ym] = $close / $lastRate;
                }
            }

            return $out;
        });
    }

    /**
     * @return array{0: array<string, float>, 1: string} ym → close, mena
     */
    protected function yahooMonthly(string $symbol, ?CarbonImmutable $from = null): array
    {
        try {
            $res = Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($symbol), [
                    // explicitný rozsah — `range=max` Yahoo pri 1mo skracuje
                    'period1' => ($from ?? CarbonImmutable::create(1960, 1, 1))->timestamp,
                    'period2' => CarbonImmutable::today()->addDay()->timestamp,
                    'interval' => '1mo',
                ]);
            if (! $res->ok()) {
                return [[], 'EUR'];
            }

            $r = data_get($res->json(), 'chart.result.0');
            $ts = data_get($r, 'timestamp', []);

            // adjclose zahŕňa reinvestované dividendy — bez neho by každý
            // rozdeľujúci fond vyzeral horšie, než v skutočnosti bol
            $cl = data_get($r, 'indicators.adjclose.0.adjclose') ?: data_get($r, 'indicators.quote.0.close', []);

            $map = [];
            foreach ($ts as $i => $t) {
                $c = $cl[$i] ?? null;
                if ($c !== null && $c > 0) {
                    $map[CarbonImmutable::createFromTimestamp($t)->format('Y-m')] = (float) $c;
                }
            }
            ksort($map);

            return [$map, (string) data_get($r, 'meta.currency', 'EUR')];
        } catch (\Throwable) {
            return [[], 'EUR'];
        }
    }

    /** @return array<string, float> ym → kurz 1 EUR = ? mena */
    protected function fxMonthly(string $currency, CarbonImmutable $from): array
    {
        try {
            $res = Http::timeout(20)->get(
                'https://api.frankfurter.dev/v1/'.$from->format('Y-m-d').'..'.CarbonImmutable::today()->format('Y-m-d'),
                ['base' => 'EUR', 'symbols' => $currency]
            );
            $byMonth = [];
            foreach (data_get($res->json(), 'rates', []) as $date => $vals) {
                $byMonth[substr($date, 0, 7)] = (float) ($vals[$currency] ?? 0);
            }

            return $byMonth;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Anualizovaný výnos (CAGR) z mesačných výnosov. */
    public function cagr(array $returns): float
    {
        if (! $returns) {
            return 0.0;
        }
        $growth = 1.0;
        foreach ($returns as $r) {
            $growth *= (1 + $r);
        }
        if ($growth <= 0) {
            return -1.0;
        }

        return $growth ** (12 / count($returns)) - 1;
    }

    /** Anualizovaná volatilita (smerodajná odchýlka mesačných výnosov × √12). */
    public function volatility(array $returns): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($returns) / $n;
        $var = 0.0;
        foreach ($returns as $r) {
            $var += ($r - $mean) ** 2;
        }

        return sqrt($var / ($n - 1)) * sqrt(12);
    }
}
