<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Period;
use Carbon\CarbonImmutable;

/**
 * Týždenné zhrnutie výdavkov pre push notifikáciu. Výdavky ráta rovnako ako
 * prehľad a Analýzy (AnalyticsService::summary) — bez vylúčených z analýz,
 * vrátení, prevodov a presunov do úspor/portfólia.
 *
 * Texty sú v aktuálnom jazyku (lang/{sk,en}/gros.php → weekly) — príkaz
 * gros:weekly-summary ho nastaví podľa users.locale.
 */
class WeeklySummaryService
{
    public function title(): string
    {
        return __('gros.weekly.title');
    }

    public function __construct(
        protected AnalyticsService $analytics,
        protected ExpenseClassifier $classifier,
    ) {}

    /**
     * Tento týždeň (pondelok – dnes), celý minulý týždeň a najväčší výdavok tohto týždňa.
     *
     * @return array{expense: float, previous: float, top: ?array{name: string, amount: float}}
     */
    public function forUser(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        $week = new Period('custom', $monday, $today, 'Tento týždeň');
        $prev = new Period('custom', $monday->subWeek(), $monday->subDay(), 'Minulý týždeň');

        $top = $week->apply($this->classifier->excludeSavings(
            $user->transactions()->analyzed()->where('type', 'expense'), $user
        ))
            ->with('category:id,name')
            ->orderByDesc(Transaction::netExpression())
            ->first();

        return [
            'expense' => $this->analytics->summary($user, $week)['expense'],
            'previous' => $this->analytics->summary($user, $prev)['expense'],
            'top' => $top && $top->net_amount > 0 ? [
                'name' => trim((string) $top->note) ?: ($top->category?->name ?? __('gros.weekly.uncategorized')),
                'amount' => $top->net_amount,
            ] : null,
        ];
    }

    /** Text notifikácie. V režime súkromia bez súm. */
    public function body(User $user, array $summary): string
    {
        if ($user->privacy_mode) {
            return __('gros.weekly.privacy');
        }

        $expense = round($summary['expense'], 2);
        if ($expense <= 0) {
            return __('gros.weekly.none');
        }

        $text = __('gros.weekly.spent', ['amount' => $this->money($expense)]);

        $previous = round($summary['previous'], 2);
        if ($previous > 0) {
            $pct = (int) round(abs($expense - $previous) / $previous * 100);
            $text .= match (true) {
                $pct === 0 => __('gros.weekly.same'),
                $expense > $previous => __('gros.weekly.more', ['pct' => $pct]),
                default => __('gros.weekly.less', ['pct' => $pct]),
            };
        }

        $text .= '.';

        if ($summary['top']) {
            $text .= __('gros.weekly.top', ['name' => $summary['top']['name'], 'amount' => $this->money($summary['top']['amount'])]);
        }

        return $text;
    }

    /** 1 312,40 € po slovensky, €1,312.40 po anglicky. */
    protected function money(float $n): string
    {
        return app()->getLocale() === 'sk'
            ? number_format($n, 2, ',', ' ').' €'
            : '€'.number_format($n, 2, '.', ',');
    }
}
