<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Historické trhové dáta na porovnanie portfólia s benchmarkom: mesačné
 * uzávierky z Yahoo Finance prepočítané do EUR. Cache na 24 h.
 */
class MarketDataService
{
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
