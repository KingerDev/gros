<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\FinancialProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Dve-tri vety o tom, čo sa za mesiac zmenilo a prečo — bez toho, aby si sa
 * musel pýtať. Fakty pripraví appka, model ich už len prerozpráva; nemá
 * priestor si niečo domyslieť, lebo dostane hotové čísla.
 *
 * Výsledok sa cache-uje, takže sa model volá raz za mesiac, nie pri každom
 * načítaní prehľadu.
 */
class MonthlyBriefing
{
    public function __construct(
        protected AiText $ai,
        protected FinanceToolkit $toolkit,
        protected FinancialProfileService $profiles,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        if (! $this->ai->configured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        $today = CarbonImmutable::today();
        $facts = $this->facts($user, $today);

        if (! $facts['ma_data']) {
            return ['ok' => false, 'reason' => 'no_data'];
        }

        // kľúč drží mesiac aj podobu dát — po novej transakcii sa prepíše
        $key = 'briefing:'.$user->id.':'.md5(json_encode($facts));

        $text = Cache::remember($key, now()->addDays(7), fn () => $this->ai->ask(
            $this->system(),
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ));

        return $text === null
            ? ['ok' => false, 'reason' => 'failed']
            : ['ok' => true, 'text' => $text, 'period' => $facts['tento_mesiac']];
    }

    /** @return array<string, mixed> */
    protected function facts(User $user, CarbonImmutable $today): array
    {
        $thisFrom = $today->startOfMonth();
        $prevFrom = $thisFrom->subMonthNoOverflow();
        $prevTo = $thisFrom->subDay();

        $compare = $this->toolkit->call($user, 'compare_periods', [
            'a_from' => $thisFrom->toDateString(), 'a_to' => $today->toDateString(),
            'b_from' => $prevFrom->toDateString(), 'b_to' => $prevTo->toDateString(),
        ]);
        $summary = $this->toolkit->call($user, 'spending_summary', [
            'from' => $thisFrom->toDateString(), 'to' => $today->toDateString(),
        ]);
        $profile = $this->profiles->forUser($user);

        return [
            'tento_mesiac' => $thisFrom->format('Y-m'),
            'den_v_mesiaci' => $today->day.'/'.$today->daysInMonth,
            'ma_data' => $summary['pocet_transakcii'] > 0,
            'vydavky_tento_mesiac' => $summary['vydavky'],
            'prijem_tento_mesiac' => $summary['prijem'],
            // predošlý mesiac je celý, tento nie — model to musí vedieť
            'vydavky_minuly_cely_mesiac' => $compare['vydavky_b'],
            'najvacsie_zmeny' => array_slice($compare['zmeny_podla_kategorie'], 0, 4),
            'dlhodoby_priemer' => [
                'prijem' => $profile['measured']['income'],
                'bezne_vydavky' => $profile['measured']['recurring_expense'],
                'ostava' => $profile['measured']['recurring_savings'],
            ],
        ];
    }

    protected function system(): string
    {
        return <<<'PROMPT'
        Si finančný asistent. Dostaneš hotové čísla za aktuálny mesiac a porovnanie s predošlým.
        Napíš z nich dve až tri vety po slovensky pre používateľa aplikácie Groš.

        Pravidlá:
        - Používaj výhradne čísla, ktoré si dostal. Nič nedopočítavaj ani neodhaduj.
        - Aktuálny mesiac ešte nie je celý (pozri den_v_mesiaci), takže ho neporovnávaj s celým
          predošlým mesiacom ako rovnocenné. Buď to spomeň, alebo porovnávaj len kategórie.
        - Začni tým, čo sa najviac zmenilo, a povedz konkrétnu kategóriu aj sumu.
        - Suchý, priateľský tón. Žiadne oslovenia, žiadne „Ahoj", žiadne rady typu „mal by si".
        - Bez odrážok a bez nadpisov, len súvislý text. Sumy zaokrúhli na celé eurá.
        PROMPT;
    }
}
