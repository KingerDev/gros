<?php

namespace App\Services\Ai;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
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

            $saved = $chat->messages()->create([
                'role' => 'assistant',
                'content' => $message['content'] ?? null,
                'tool_calls' => $toolCalls,
            ]);

            if (! $toolCalls) {
                $chat->update(['last_message_at' => now()]);

                return $saved;
            }

            // model si vypýtal dáta — spustíme nástroje a vrátime mu výsledky
            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];

                try {
                    $result = $this->toolkit->call($user, $name, $args);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }

                $chat->messages()->create([
                    'role' => 'tool',
                    'name' => $name,
                    'tool_call_id' => $call['id'] ?? null,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ]);
            }
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

        $history = $chat->messages()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()
            ->reverse()->values()
            // konverzácia nesmie začať výsledkom nástroja bez jeho volania
            ->skipUntil(fn (ChatMessage $m) => $m->role !== 'tool')
            ->map(fn (ChatMessage $m) => $m->toApi())
            ->values()->all();

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

    protected function systemPrompt(User $user): string
    {
        $today = CarbonImmutable::today();

        return <<<PROMPT
        Si finančný asistent v aplikácii Groš. Odpovedáš v slovenčine, vecne a stručne.

        Dnes je {$today->toDateString()}. Používateľ sa volá {$user->name}. Meny sú v eurách.

        Ako pracuješ:
        - Dáta si vypýtaj cez nástroje. Nikdy si sumy, kategórie ani dátumy nevymýšľaj a neodhaduj.
        - Keď sa pýta „prečo som minul viac", najprv porovnaj obdobia (compare_periods), potom si vypýtaj
          konkrétne transakcie (list_transactions) z kategórií, ktoré narástli najviac. Odpoveď vždy podlož
          konkrétnymi položkami s dátumom a sumou.
        - Ak nástroj nevráti dáta, povedz to priamo. Nedomýšľaj si chýbajúce čísla.
        - Sumy uvádzaj zaokrúhlené na celé eurá, ak nejde o malé sumy.
        - Buď stručný. Odpoveď na jednoduchú otázku sú dve-tri vety, nie esej. Odrážky použi len na zoznamy položiek.

        Čo nerobíš:
        - Nedávaš investičné odporúčania typu „kúp/predaj toto". Nie si licencovaný poradca. Fakty
          o portfóliu (koncentrácia, volatilita, výnos) hovoriť môžeš.
        - Nemáš prístup na zápis. Ak chce používateľ niečo zmeniť, povedz mu, na ktorej stránke to urobí.
        PROMPT;
    }
}
