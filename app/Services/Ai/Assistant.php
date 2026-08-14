<?php

namespace App\Services\Ai;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finančný asistent nad dátami používateľa.
 *
 * Model nedostane dáta v prompte — vypýta si ich cez nástroje (function
 * calling). Vďaka tomu vie odpoveď oprieť o konkrétne transakcie a nemusí sa
 * do kontextu vojsť celá história. Slučka beží dovtedy, kým model prestane
 * pýtať nástroje, najviac však `MAX_ROUNDS` kôl.
 */
class Assistant
{
    /** Koľkokrát smie model po sebe siahnuť po nástrojoch. */
    protected const MAX_ROUNDS = 6;

    /** Koľko posledných správ sa modelu posiela — staršie sa orežú. */
    protected const HISTORY_LIMIT = 40;

    public function __construct(protected FinanceToolkit $toolkit) {}

    /**
     * Pošle otázku do chatu a uloží celú výmenu vrátane volaní nástrojov.
     *
     * @return ChatMessage odpoveď asistenta
     */
    public function ask(Chat $chat, string $question): ChatMessage
    {
        $user = $chat->user;

        $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $chat->titleFrom($question);
        $chat->update(['last_message_at' => now()]);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $response = $this->completion($user, $chat);
            $message = $response['choices'][0]['message'] ?? null;

            if (! $message) {
                throw new RuntimeException('Model nevrátil odpoveď.');
            }

            $toolCalls = $message['tool_calls'] ?? null;

            if (! $toolCalls) {
                $saved = $chat->messages()->create([
                    'role' => 'assistant',
                    'content' => $message['content'] ?? null,
                ]);
                $chat->update(['last_message_at' => now()]);

                return $saved;
            }

            // Model si vypýtal dáta. Nástroje spustíme ešte pred zápisom, aby
            // sa volanie aj jeho výsledky uložili naraz — polovica dvojice by
            // chat natrvalo pokazila (rozhranie modelu ju odmieta).
            $results = [];
            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];

                try {
                    $result = $this->toolkit->call($user, $name, $args);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }

                $results[] = [
                    'role' => 'tool',
                    'name' => $name,
                    'tool_call_id' => $call['id'] ?? null,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }

            DB::transaction(function () use ($chat, $message, $toolCalls, $results) {
                $chat->messages()->create([
                    'role' => 'assistant',
                    'content' => $message['content'] ?? null,
                    'tool_calls' => $toolCalls,
                ]);

                foreach ($results as $result) {
                    $chat->messages()->create($result);
                }
            });
        }

        // poistka proti nekonečnému dopytovaniu
        return $chat->messages()->create([
            'role' => 'assistant',
            'content' => 'Nepodarilo sa mi dopátrať k odpovedi — skús otázku položiť konkrétnejšie.',
        ]);
    }

    /** @return array<string, mixed> */
    protected function completion(User $user, Chat $chat): array
    {
        $key = config('services.openai.key');
        if (! $key) {
            throw new RuntimeException('Chýba OPENAI_API_KEY v .env.');
        }

        $history = $this->history($chat);

        $res = Http::withToken($key)
            ->timeout((int) config('services.openai.timeout', 120))
            ->post(rtrim(config('services.openai.base_url'), '/').'/chat/completions', [
                'model' => config('services.openai.model'),
                'messages' => [['role' => 'system', 'content' => $this->systemPrompt($user)], ...$history],
                'tools' => $this->toolkit->definitions(),
            ]);

        if (! $res->successful()) {
            $detail = data_get($res->json(), 'error.message', $res->body());
            throw new RuntimeException('Model odmietol požiadavku: '.mb_strimwidth((string) $detail, 0, 200, '…'));
        }

        return $res->json();
    }

    /**
     * História pre model — orezaná a očistená o rozbité dvojice volanie/výsledok.
     *
     * Vzniknúť môžu dvoma spôsobmi: kolo sa prerušilo po uložení volania, ale
     * pred uložením výsledkov (staršie chaty), alebo orezanie na HISTORY_LIMIT
     * odreže volanie a nechá visieť výsledky. Rozhranie modelu oboje odmieta
     * chybou 400, takže bez tejto očisty by sa chat už nedal použiť.
     *
     * @return list<array<string, mixed>>
     */
    protected function history(Chat $chat): array
    {
        $messages = $chat->messages()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()
            ->reverse()->values();

        $answered = $messages->where('role', 'tool')->pluck('tool_call_id')->filter()->flip();

        // volanie nástroja nechávame len vtedy, keď má odpoveď každý jeho tool_call_id
        $kept = $messages->reject(fn (ChatMessage $m) => $m->role === 'assistant' && $m->tool_calls
            && collect($m->tool_calls)->contains(fn ($c) => ! isset($answered[$c['id'] ?? ''])));

        $calledIds = $kept->where('role', 'assistant')->flatMap(fn (ChatMessage $m) => collect($m->tool_calls ?? [])
            ->pluck('id')->filter())->flip();

        return $kept
            // a výsledky len tie, ktorým volanie ostalo
            ->reject(fn (ChatMessage $m) => $m->role === 'tool' && ! isset($calledIds[$m->tool_call_id]))
            // prázdna asistentova správa po zahodení volania nenesie nič
            ->reject(fn (ChatMessage $m) => $m->role === 'assistant' && ! $m->tool_calls
                && trim((string) $m->content) === '')
            ->map(fn (ChatMessage $m) => $m->toApi())
            ->values()->all();
    }

    protected function systemPrompt(User $user): string
    {
        $today = CarbonImmutable::today();

        return <<<PROMPT
        Si finančný asistent v aplikácii Groš. Odpovedáš v slovenčine, vecne a stručne.

        Dnes je {$today->toDateString()}. Používateľ sa volá {$user->name}. Meny sú v eurách.

        Ako pracuješ:
        - Dáta si ťaháš cez nástroje, sám a hneď. Nikdy sa nepýtaj na dovolenie („môžem sa pozrieť?",
          „chceš, aby som ti ukázal?") a nikdy nepýtaj od používateľa čísla, ktoré si vieš zistiť sám.
          Používateľ sa pýta preto, že chce odpoveď — nie ponuku, že mu ju vieš zistiť.
        - Nikdy nepovedz, že dáta nemáš, kým si po nich nesiahol. Keď nevieš, ktorý nástroj sedí,
          začni s financial_overview alebo investment_portfolio.
        - Nikdy si sumy, kategórie ani dátumy nevymýšľaj a neodhaduj.
        - Keď sa pýta „prečo som minul viac", najprv porovnaj obdobia (compare_periods), potom si vypýtaj
          konkrétne transakcie (list_transactions) z kategórií, ktoré narástli najviac. Odpoveď vždy podlož
          konkrétnymi položkami s dátumom a sumou.
        - Ak nástroj nevráti dáta, povedz to priamo. Nedomýšľaj si chýbajúce čísla.
        - Sumy uvádzaj zaokrúhlené na celé eurá, ak nejde o malé sumy.
        - Buď stručný. Odpoveď na jednoduchú otázku sú dve-tri vety, nie esej. Odrážky použi len na zoznamy položiek.

        Otázky na investície (napr. „čo povieš na 200 € do BTC?") sú otázky ako každé iné — nevyhýbaj sa im.
        Vytiahni si portfólio a financie a povedz fakty: koľko by tá suma tvorila z portfólia, ako by zmenila
        koncentráciu, či na ňu ostáva po fixných výdavkoch a či je núdzová rezerva plná. Rozhodnutie necháš
        na používateľa — nepovieš „kúp" ani „nekupuj", lebo nie si licencovaný poradca. Odmietnuť celú
        odpoveď je ale horšie než ju podložiť číslami.

        Čo nerobíš:
        - Nemáš prístup na zápis. Ak chce používateľ niečo zmeniť, povedz mu, na ktorej stránke to urobí.
        PROMPT;
    }
}
