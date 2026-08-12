<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use App\Models\InvestmentTransaction;
use App\Services\PortfolioHistoryService;
use App\Services\PriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvestmentController extends Controller
{
    public function index(Request $request, PriceService $prices): Response
    {
        $user = $request->user();

        // Auto-refresh ak sú ceny staršie ako 15 minút (alebo chýbajú)
        $stale = $user->investments()
            ->whereIn('quote_source', ['yahoo', 'coingecko'])
            ->whereNotNull('quote_symbol')
            ->where(fn ($q) => $q->whereNull('last_price_at')->orWhere('last_price_at', '<', now()->subMinutes(15)))
            ->exists();
        if ($stale) {
            try {
                $prices->updateForUser($user);
            } catch (\Throwable) {
                // ticho — stránka sa zobrazí so starými cenami
            }
        }

        $investments = $user->investments()->with('lots')->orderByDesc('id')->get();

        $value = 0.0;
        $cost = 0.0;
        foreach ($investments as $i) {
            $value += $i->value;
            $cost += $i->cost;
        }

        return Inertia::render('gros/Investments', [
            'investments' => $investments->map(fn (Investment $i) => [
                'id' => $i->id,
                'ticker' => $i->ticker,
                'name' => $i->name,
                'kind' => $i->kind,
                'quote_symbol' => $i->quote_symbol,
                'quote_source' => $i->quote_source,
                'units' => (float) $i->units,
                'buy_price' => (float) $i->buy_price,
                'current_price' => (float) $i->current_price,
                'last_price_at' => $i->last_price_at?->toIso8601String(),
                'color' => $i->color,
                'value' => $i->value,
                'cost' => $i->cost,
                'gain' => $i->gain,
                'realized' => $i->realized_gain,
                'lots' => $i->lots->map(fn ($l) => [
                    'id' => $l->id,
                    'type' => $l->type,
                    'units' => (float) $l->units,
                    'price' => (float) $l->price,
                    'date' => $l->date->toDateString(),
                    'note' => $l->note,
                ]),
            ]),
            'totals' => [
                'value' => $value,
                'cost' => $cost,
                'gain' => $value - $cost,
                'pct' => $cost > 0 ? ($value - $cost) / $cost * 100 : 0,
            ],
        ]);
    }

    public function store(Request $request, PriceService $prices): RedirectResponse
    {
        $data = $request->validate([
            'ticker' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['etf', 'stock', 'crypto'])],
            'quote_source' => ['required', Rule::in(['manual', 'yahoo', 'coingecko'])],
            'quote_symbol' => ['nullable', 'required_unless:quote_source,manual', 'string', 'max:40'],
            'current_price' => ['nullable', 'numeric', 'min:0'],
            // voliteľný prvý nákup
            'units' => ['nullable', 'numeric', 'min:0'],
            'buy_price' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
        ]);

        $count = $request->user()->investments()->count();
        $palette = config('gros.palette');

        $inv = $request->user()->investments()->create([
            'ticker' => strtoupper($data['ticker']),
            'name' => $data['name'],
            'kind' => $data['kind'],
            'quote_symbol' => $data['quote_symbol'] ?? null,
            'quote_source' => $data['quote_source'],
            'current_price' => $data['current_price'] ?? 0,
            'color' => $palette[$count % count($palette)],
        ]);

        // prvý nákup
        if (! empty($data['units']) && $data['units'] > 0) {
            $inv->lots()->create([
                'type' => 'buy',
                'units' => $data['units'],
                'price' => $data['buy_price'] ?? 0,
                'date' => $data['date'] ?? now()->toDateString(),
            ]);
            $inv->recomputeFromLots();
        }

        // hneď stiahni cenu ak je auto
        if ($inv->quote_source !== 'manual') {
            try {
                $prices->updateInvestments(collect([$inv]));
            } catch (\Throwable) {
            }
        }

        return back()->with('success', 'Investícia pridaná.');
    }

    public function update(Request $request, Investment $investment): RedirectResponse
    {
        abort_unless($investment->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'ticker' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['etf', 'stock', 'crypto'])],
            'quote_source' => ['required', Rule::in(['manual', 'yahoo', 'coingecko'])],
            'quote_symbol' => ['nullable', 'required_unless:quote_source,manual', 'string', 'max:40'],
            'current_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['ticker'] = strtoupper($data['ticker']);
        if ($data['quote_source'] === 'manual' && isset($data['current_price'])) {
            $investment->current_price = $data['current_price'];
        }

        $investment->update([
            'ticker' => $data['ticker'],
            'name' => $data['name'],
            'kind' => $data['kind'],
            'quote_symbol' => $data['quote_symbol'] ?? null,
            'quote_source' => $data['quote_source'],
            'current_price' => $investment->current_price,
        ]);

        return back()->with('success', 'Investícia upravená.');
    }

    public function destroy(Request $request, Investment $investment): RedirectResponse
    {
        abort_unless($investment->user_id === $request->user()->id, 403);
        $investment->delete();

        return back()->with('success', 'Investícia zmazaná.');
    }

    /** Pridá nákup/predaj (lot) a prepočíta pozíciu. */
    public function storeLot(Request $request, Investment $investment): RedirectResponse
    {
        abort_unless($investment->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['buy', 'sell'])],
            'units' => ['required', 'numeric', 'min:0.00000001'],
            'price' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $investment->lots()->create($data);
        $investment->recomputeFromLots();

        return back()->with('success', $data['type'] === 'buy' ? 'Nákup pridaný.' : 'Predaj pridaný.');
    }

    public function destroyLot(Request $request, Investment $investment, InvestmentTransaction $lot): RedirectResponse
    {
        abort_unless($investment->user_id === $request->user()->id && $lot->investment_id === $investment->id, 403);

        $lot->delete();
        $investment->recomputeFromLots();

        return back()->with('success', 'Záznam zmazaný.');
    }

    /** Vývoj hodnoty portfólia v čase (JSON, lazy načítanie na stránke). */
    public function history(Request $request, PortfolioHistoryService $svc): JsonResponse
    {
        $user = $request->user();
        $data = $svc->monthlySeries($user);

        $holdings = $user->investments()->get()
            ->filter(fn ($i) => (float) $i->units > 0)
            ->map(fn ($i) => [
                'ticker' => $i->ticker,
                'name' => $i->name,
                'color' => $i->color,
                'value' => $i->value,
                'gain' => $i->gain,
                'pct' => $i->cost > 0 ? round($i->gain / $i->cost * 100, 1) : 0,
            ])
            ->sortByDesc('pct')
            ->values();

        return response()->json([...$data, 'holdings' => $holdings]);
    }

    /** Historická cena za kus k dátumu (JSON pre formulár nákupu). */
    public function historicalPrice(Request $request, Investment $investment, PriceService $prices): JsonResponse
    {
        abort_unless($investment->user_id === $request->user()->id, 403);

        $data = $request->validate(['date' => ['required', 'date']]);
        $price = $prices->historicalPrice($investment, $data['date']);

        return response()->json(['price' => $price]);
    }

    /** Manuálne stiahnutie aktuálnych cien. */
    public function refresh(Request $request, PriceService $prices): RedirectResponse
    {
        $r = $prices->updateForUser($request->user());

        if ($r['updated'] > 0 && $r['failed'] === 0) {
            return back()->with('success', "Ceny aktualizované ({$r['updated']}).");
        }
        if ($r['updated'] === 0 && $r['failed'] === 0) {
            return back()->with('success', 'Žiadne auto-pozície na aktualizáciu.');
        }

        return back()->with('error', "Aktualizované: {$r['updated']}, zlyhalo: {$r['failed']}. ".implode(' · ', array_slice($r['errors'], 0, 3)));
    }
}
