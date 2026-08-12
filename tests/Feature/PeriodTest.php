<?php

namespace Tests\Feature;

use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Hranice obdobia sú tichý zdroj chýb — posun o jeden deň nikto nevidí,
 * len čísla nesedia. Preto sú tu vypísané doslova.
 */
class PeriodTest extends TestCase
{
    /** @param  array<string, string|null>  $query */
    protected function period(array $query): Period
    {
        $request = Request::create('/analytics', 'GET', $query);
        $request->setLaravelSession(app('session.store'));

        return Period::fromRequest($request);
    }

    public function test_month_covers_the_whole_month(): void
    {
        $p = $this->period(['period' => 'month', 'ref' => '2026-02']);

        $this->assertSame('2026-02-01', $p->from->toDateString());
        $this->assertSame('2026-02-28', $p->to->toDateString());
        $this->assertSame('Február 2026', $p->label);
    }

    public function test_february_of_a_leap_year_has_29_days(): void
    {
        $p = $this->period(['period' => 'month', 'ref' => '2024-02']);

        $this->assertSame('2024-02-29', $p->to->toDateString());
    }

    public function test_previous_month_crosses_the_year_boundary(): void
    {
        $prev = $this->period(['period' => 'month', 'ref' => '2026-01'])->previous();

        $this->assertSame('2025-12-01', $prev->from->toDateString());
        $this->assertSame('2025-12-31', $prev->to->toDateString());
        $this->assertSame('December 2025', $prev->label);
    }

    /** Marec má 31 dní, február 28 — posun nesmie pretiecť do marca. */
    public function test_previous_month_of_a_31_day_month_keeps_full_months(): void
    {
        $prev = $this->period(['period' => 'month', 'ref' => '2026-03'])->previous();

        $this->assertSame('2026-02-01', $prev->from->toDateString());
        $this->assertSame('2026-02-28', $prev->to->toDateString());
    }

    public function test_year_covers_the_whole_year_and_steps_back_by_one(): void
    {
        $p = $this->period(['period' => 'year', 'ref' => '2025']);

        $this->assertSame('2025-01-01', $p->from->toDateString());
        $this->assertSame('2025-12-31', $p->to->toDateString());
        $this->assertSame('2024', $p->previous()->label);
        $this->assertSame('2024-01-01', $p->previous()->from->toDateString());
    }

    public function test_thirty_days_ends_today_and_previous_window_ends_the_day_before(): void
    {
        $today = CarbonImmutable::today();
        $p = $this->period(['period' => '30d']);

        $this->assertSame($today->subDays(30)->toDateString(), $p->from->toDateString());
        $this->assertSame($today->toDateString(), $p->to->toDateString());

        $prev = $p->previous();
        $this->assertSame($p->from->subDay()->toDateString(), $prev->to->toDateString());
        $this->assertSame(30, (int) $prev->from->diffInDays($prev->to));
    }

    public function test_all_has_no_bounds_and_no_previous(): void
    {
        $p = $this->period(['period' => 'all']);

        $this->assertNull($p->from);
        $this->assertNull($p->to);
        $this->assertNull($p->previous());
    }

    public function test_period_is_remembered_in_the_session(): void
    {
        $request = Request::create('/analytics', 'GET', ['period' => 'year', 'ref' => '2024']);
        $request->setLaravelSession($session = app('session.store'));
        Period::fromRequest($request);

        // Ďalšia stránka už parametre neposiela — obdobie sa má zachovať
        $next = Request::create('/dashboard');
        $next->setLaravelSession($session);

        $this->assertSame('2024', Period::fromRequest($next)->label);
    }

    public function test_defaults_to_the_current_month(): void
    {
        $p = $this->period([]);

        $this->assertSame('month', $p->key);
        $this->assertSame(CarbonImmutable::today()->startOfMonth()->toDateString(), $p->from->toDateString());
    }
}
