<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PriceService;
use Illuminate\Console\Command;

class UpdatePrices extends Command
{
    protected $signature = 'gros:update-prices {--email= : len konkrétny používateľ}';

    protected $description = 'Stiahne aktuálne ceny investícií (CoinGecko + Yahoo).';

    public function handle(PriceService $prices): int
    {
        $users = $this->option('email')
            ? User::where('email', $this->option('email'))->get()
            : User::whereHas('investments', fn ($q) => $q->whereIn('quote_source', ['yahoo', 'coingecko']))->get();

        $totUp = 0;
        $totFail = 0;
        foreach ($users as $user) {
            $r = $prices->updateForUser($user);
            $totUp += $r['updated'];
            $totFail += $r['failed'];
            foreach ($r['errors'] as $e) {
                $this->warn("  [{$user->email}] $e");
            }
        }

        $this->info("Aktualizované: $totUp | zlyhalo: $totFail");

        return self::SUCCESS;
    }
}
