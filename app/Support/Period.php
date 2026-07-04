<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Rozsah obdobia zo query parametrov — zdieľané prehľadom aj Analýzami.
 *  ?period=all|30d|month|year|custom  &ref=2026-05 (mesiac) / 2026 (rok)  &from=&to=
 */
class Period
{
    public function __construct(
        public string $key,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public string $label,
        public ?string $ref = null,
    ) {}

    /**
     * Obdobie je globálne: ak prídu parametre (z prepínača), zapamätajú sa do
     * session a použijú sa na každej stránke, kým ich nezmeníš.
     */
    public static function fromRequest(Request $request): self
    {
        if ($request->filled('period')) {
            $data = [
                'period' => $request->query('period'),
                'ref' => $request->query('ref'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ];
            $request->session()->put('gros_period', $data);
        } else {
            $data = $request->session()->get('gros_period', ['period' => 'month', 'ref' => null, 'from' => null, 'to' => null]);
        }

        return self::resolve($data['period'] ?? 'month', $data['ref'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
    }

    protected static function resolve(string $key, ?string $ref, ?string $from, ?string $to): self
    {
        $today = CarbonImmutable::today();

        return match ($key) {
            'all' => new self('all', null, null, 'Celé obdobie'),
            '30d' => new self('30d', $today->subDays(30), $today, 'Posledných 30 dní'),
            'year' => (function () use ($ref, $today) {
                $y = (int) ($ref ?: $today->year);
                $d = CarbonImmutable::create($y, 1, 1);

                return new self('year', $d->startOfYear(), $d->endOfYear(), (string) $y, (string) $y);
            })(),
            'custom' => (function () use ($from, $to) {
                $f = $from ? CarbonImmutable::parse($from) : null;
                $t = $to ? CarbonImmutable::parse($to) : null;
                $label = ($f?->format('d.m.Y') ?? '…').' – '.($t?->format('d.m.Y') ?? '…');

                return new self('custom', $f, $t, $label);
            })(),
            default => (function () use ($ref, $today) {
                $d = $ref ? CarbonImmutable::parse($ref.'-01') : $today->startOfMonth();

                return new self('month', $d->startOfMonth(), $d->endOfMonth(), self::monthLabel($d), $d->format('Y-m'));
            })(),
        };
    }

    /** Predchádzajúce obdobie rovnakej dĺžky (na porovnanie ▲/▼). Null pre 'all' a neúplný custom. */
    public function previous(): ?self
    {
        if (! $this->from || ! $this->to) {
            return null;
        }

        if ($this->key === 'month') {
            $m = $this->from->subMonthsNoOverflow(1);

            return new self('month', $m->startOfMonth(), $m->endOfMonth(), self::monthLabel($m), $m->format('Y-m'));
        }

        if ($this->key === 'year') {
            $y = $this->from->subYear();

            return new self('year', $y->startOfYear(), $y->endOfYear(), (string) $y->year, (string) $y->year);
        }

        $days = $this->from->diffInDays($this->to);
        $to = $this->from->subDay();
        $from = $to->subDays($days);

        return new self($this->key, $from, $to, $from->format('d.m.Y').' – '.$to->format('d.m.Y'));
    }

    /** Aplikuje rozsah na query (stĺpec date). */
    public function apply($query, string $column = 'date')
    {
        if ($this->from) {
            $query->where($column, '>=', $this->from->toDateString());
        }
        if ($this->to) {
            $query->where($column, '<=', $this->to->toDateString());
        }

        return $query;
    }

    public static function monthLabel(CarbonImmutable $d): string
    {
        $m = ['', 'Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún', 'Júl', 'August', 'September', 'Október', 'November', 'December'];

        return $m[$d->month].' '.$d->year;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'ref' => $this->ref,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'label' => $this->label,
        ];
    }
}
