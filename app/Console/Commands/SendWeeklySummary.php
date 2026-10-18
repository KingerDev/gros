<?php

namespace App\Console\Commands;

use App\Models\PushToken;
use App\Models\User;
use App\Services\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

/**
 * Nedeľné push zhrnutie týždňa cez Expo Push Service — jedna notifikácia
 * na každé zaregistrované zariadenie. Zariadenia, ktoré Expo hlási ako
 * odhlásené (DeviceNotRegistered), sa zmažú.
 */
class SendWeeklySummary extends Command
{
    protected $signature = 'gros:weekly-summary
        {--dry-run : Len vypíše správy, nič neodošle}';

    protected $description = 'Pošle do mobilnej appky push so zhrnutím výdavkov za aktuálny týždeň.';

    public const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

    /** Expo prijme najviac 100 správ v jednom requeste. */
    protected const CHUNK = 100;

    public function handle(WeeklySummaryService $summary): int
    {
        // týždeň sa ráta podľa slovenského kalendára, nie podľa UTC servera
        $today = CarbonImmutable::parse(CarbonImmutable::now('Europe/Bratislava')->toDateString());

        $messages = [];
        $appLocale = App::getLocale();

        User::whereHas('pushTokens')->with('pushTokens')->each(function (User $user) use ($summary, $today, &$messages) {
            // notifikácia v jazyku používateľa (appka ho posiela v Accept-Language)
            App::setLocale($user->locale ?: 'sk');

            $title = $summary->title();
            $body = $summary->body($user, $summary->forUser($user, $today));

            foreach ($user->pushTokens as $token) {
                $messages[] = [
                    'to' => $token->token,
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'data' => ['url' => '/'],
                ];
            }
        });

        App::setLocale($appLocale);

        if (! $messages) {
            $this->info('Žiadne zaregistrované zariadenia.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($messages as $m) {
                $this->line($m['to'].'  '.$m['body']);
            }
            $this->comment('Nič sa neodoslalo (--dry-run).');

            return self::SUCCESS;
        }

        $sent = 0;
        $removed = 0;

        foreach (array_chunk($messages, self::CHUNK) as $chunk) {
            $response = Http::acceptJson()->timeout(30)->post(self::EXPO_URL, $chunk);

            if ($response->failed()) {
                $this->error('Expo odpovedalo chybou '.$response->status().': '.$response->body());

                continue;
            }

            // tickety prichádzajú v rovnakom poradí ako správy
            foreach ($response->json('data', []) as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'ok') {
                    $sent++;

                    continue;
                }

                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($chunk[$i])) {
                    $removed += PushToken::where('token', $chunk[$i]['to'])->delete();
                } else {
                    $this->warn(($chunk[$i]['to'] ?? '?').': '.($ticket['message'] ?? 'neznáma chyba'));
                }
            }
        }

        $this->info("Odoslaných notifikácií: $sent, zmazaných neplatných zariadení: $removed");

        return self::SUCCESS;
    }
}
