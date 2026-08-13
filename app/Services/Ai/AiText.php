<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Jednoduché volanie modelu bez nástrojov — na krátke odpovede, kde stačí
 * text alebo malý JSON. Chat s prístupom k dátam rieši {@see Assistant}.
 */
class AiText
{
    public function configured(): bool
    {
        return (bool) config('services.openai.key');
    }

    /**
     * Vráti text odpovede, alebo null, keď model nie je nastavený či zlyhá.
     * Volajúci sa preto nikdy nemusí báť, že mu AI zhodí stránku.
     */
    public function ask(string $system, string $user, ?string $model = null, int $timeout = 45): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $res = Http::withToken(config('services.openai.key'))
                ->timeout($timeout)
                ->post(rtrim(config('services.openai.base_url'), '/').'/chat/completions', [
                    'model' => $model ?? config('services.openai.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if (! $res->successful()) {
                Log::warning('AI odpoveď zlyhala', ['status' => $res->status()]);

                return null;
            }

            $text = data_get($res->json(), 'choices.0.message.content');

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('AI volanie zlyhalo', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * To isté, ale s očakávaním JSON objektu v odpovedi.
     *
     * @return array<string, mixed>|null
     */
    public function askJson(string $system, string $user, ?string $model = null): ?array
    {
        $text = $this->ask($system.' Odpovedz výhradne platným JSON objektom, bez akéhokoľvek iného textu.', $user, $model);
        if ($text === null) {
            return null;
        }

        // model občas obalí JSON do bloku ```json ... ```
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    /** @throws RuntimeException keď nie je nastavený kľúč */
    public function requireConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Chýba OPENAI_API_KEY v .env.');
        }
    }
}
