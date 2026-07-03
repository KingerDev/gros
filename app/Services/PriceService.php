<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Sťahuje aktuálne ceny investícií (v EUR).
 *  - crypto  → CoinGecko (quote_symbol = coingecko id, napr. "bitcoin")
 *  - akcie/ETF → Yahoo Finance v8 chart (quote_symbol = napr. "VWCE.DE", "O")
 * Ceny v inej mene (napr. USD) sa prepočítajú cez Frankfurter (ECB kurzy).
 */
class PriceService
{
    /** @var array<string, float> EUR → mena kurzy (cache v rámci behu) */
    protected array $fxCache = [];

    /**
     * Aktualizuje ceny všetkých auto-pozícií používateľa.
     *
     * @return array{updated: int, failed: int, errors: array<int, string>}
     */
    public function updateForUser(User $user): array
    {
        $investments = $user->investments()
            ->whereIn('quote_source', ['yahoo', 'coingecko'])
            ->whereNotNull('quote_symbol')
            ->get();

        return $this->updateInvestments($investments);
    }

    /**
     * @param  Collection<int, Investment>  $investments
     * @return array{updated: int, failed: int, errors: array<int, string>}
     */
    public function updateInvestments(Collection $investments): array
    {
        $updated = 0;
        $failed = 0;
        $errors = [];

        // --- CoinGecko (dávkovo) ---
        $crypto = $investments->where('quote_source', 'coingecko');
        if ($crypto->isNotEmpty()) {
            try {
                $ids = $crypto->pluck('quote_symbol')->unique()->implode(',');
                $res = Http::timeout(15)->get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => $ids,
                    'vs_currencies' => 'eur',
                ]);
                $prices = $res->json();
                foreach ($crypto as $inv) {
                    $eur = data_get($prices, $inv->quote_symbol.'.eur');
                    if ($eur !== null) {
                        $inv->update(['current_price' => round((float) $eur, 8), 'last_price_at' => now()]);
                        $updated++;
                    } else {
                        $failed++;
                        $errors[] = "{$inv->ticker}: CoinGecko nevrátil cenu pre '{$inv->quote_symbol}'";
                    }
                }
            } catch (\Throwable $e) {
                $failed += $crypto->count();
                $errors[] = 'CoinGecko chyba: '.$e->getMessage();
            }
        }

        // --- Yahoo (po jednom, s malým odstupom kvôli rate-limitu) ---
        $stocks = $investments->where('quote_source', 'yahoo');
        foreach ($stocks as $inv) {
            try {
                $res = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($inv->quote_symbol), [
                        'range' => '1d', 'interval' => '1d',
                    ]);

                if (! $res->ok()) {
                    $failed++;
                    $errors[] = "{$inv->ticker}: Yahoo HTTP {$res->status()}";
                    usleep(400_000);

                    continue;
                }

                $meta = data_get($res->json(), 'chart.result.0.meta');
                $price = data_get($meta, 'regularMarketPrice');
                $currency = data_get($meta, 'currency', 'EUR');

                if ($price === null) {
                    $failed++;
                    $errors[] = "{$inv->ticker}: Yahoo nevrátil cenu pre '{$inv->quote_symbol}'";
                } else {
                    $eur = $currency === 'EUR' ? (float) $price : $this->toEur((float) $price, $currency);
                    if ($eur === null) {
                        $failed++;
                        $errors[] = "{$inv->ticker}: nepodarilo sa prepočítať {$currency}→EUR";
                    } else {
                        $inv->update(['current_price' => round($eur, 8), 'last_price_at' => now()]);
                        $updated++;
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$inv->ticker}: {$e->getMessage()}";
            }
            usleep(400_000); // ~0,4 s medzi Yahoo requestami
        }

        return ['updated' => $updated, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Historická cena za kus v EUR k danému dátumu (Yahoo pre všetko — aj crypto
     * ako {TICKER}-EUR). Vráti cenu z daného dňa, inak najbližší predošlý deň.
     */
    public function historicalPrice(Investment $inv, string $date): ?float
    {
        $symbol = $this->yahooSymbolFor($inv);
        if (! $symbol) {
            return null;
        }

        try {
            $day = \Carbon\CarbonImmutable::parse($date);
            $res = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($symbol), [
                    'period1' => $day->subDays(6)->timestamp,
                    'period2' => $day->addDays(2)->timestamp,
                    'interval' => '1d',
                ]);
            if (! $res->ok()) {
                return null;
            }

            $result = data_get($res->json(), 'chart.result.0');
            $timestamps = data_get($result, 'timestamp', []);
            $closes = data_get($result, 'indicators.quote.0.close', []);
            $currency = data_get($result, 'meta.currency', 'EUR');

            // mapa deň → close, vyber presný deň alebo najbližší predošlý
            $byDate = [];
            foreach ($timestamps as $i => $ts) {
                $c = $closes[$i] ?? null;
                if ($c !== null) {
                    $byDate[\Carbon\CarbonImmutable::createFromTimestamp($ts)->toDateString()] = (float) $c;
                }
            }
            if (! $byDate) {
                return null;
            }

            $target = $day->toDateString();
            $price = $byDate[$target] ?? null;
            if ($price === null) {
                $prior = array_filter(array_keys($byDate), fn ($d) => $d <= $target);
                if (! $prior) {
                    return null;
                }
                $price = $byDate[max($prior)];
            }

            if ($currency !== 'EUR') {
                $rate = $this->eurRateOn($date, $currency);
                if (! $rate) {
                    return null;
                }
                $price /= $rate;
            }

            return round($price, 8);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Yahoo symbol pre inštrument: akcie/ETF = quote_symbol, crypto = TICKER-EUR. */
    protected function yahooSymbolFor(Investment $inv): ?string
    {
        if ($inv->quote_source === 'yahoo' && $inv->quote_symbol) {
            return $inv->quote_symbol;
        }
        if ($inv->kind === 'crypto') {
            return strtoupper($inv->ticker).'-EUR';
        }

        return null;
    }

    /** Prepočet z danej meny do EUR cez Frankfurter (ECB). */
    protected function toEur(float $amount, string $currency): ?float
    {
        $rate = $this->eurRate($currency); // 1 EUR = rate * currency
        if (! $rate) {
            return null;
        }

        return $amount / $rate;
    }

    /** Dobový kurz 1 EUR = ? mena k dátumu. */
    protected function eurRateOn(string $date, string $currency): ?float
    {
        try {
            $res = Http::timeout(15)->get("https://api.frankfurter.dev/v1/$date", [
                'base' => 'EUR', 'symbols' => $currency,
            ]);
            $rate = data_get($res->json(), "rates.$currency");

            return $rate ? (float) $rate : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function eurRate(string $currency): ?float
    {
        if (isset($this->fxCache[$currency])) {
            return $this->fxCache[$currency];
        }
        try {
            $res = Http::timeout(15)->get('https://api.frankfurter.dev/v1/latest', [
                'base' => 'EUR', 'symbols' => $currency,
            ]);
            $rate = data_get($res->json(), "rates.$currency");
            if ($rate) {
                return $this->fxCache[$currency] = (float) $rate;
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
