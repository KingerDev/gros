<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportWallet extends Command
{
    protected $signature = 'gros:import-wallet {json : cesta k wallet_import.json} {--email= : e-mail používateľa (default prvý)} {--force : povoliť import aj keď už má transakcie}';

    protected $description = 'Naimportuje účty a transakcie z Wallet exportu (normalizovaný JSON).';

    public function handle(): int
    {
        $path = $this->argument('json');
        if (! is_file($path)) {
            $this->error("Súbor neexistuje: $path");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! $data || ! isset($data['accounts'], $data['items'])) {
            $this->error('Neplatný JSON (chýba accounts / items).');

            return self::FAILURE;
        }

        $user = $this->option('email')
            ? User::where('email', $this->option('email'))->first()
            : User::first();
        if (! $user) {
            $this->error('Používateľ sa nenašiel.');

            return self::FAILURE;
        }
        $this->info("Používateľ: {$user->email}");

        if ($user->transactions()->exists() && ! $this->option('force')) {
            $this->error('Používateľ už má transakcie. Použi --force ak to naozaj chceš.');

            return self::FAILURE;
        }

        // --- Kategórie: (názov|typ) -> id, s fallbackom len na názov ---
        // Wallet umožňuje transakciu s kategóriou opačného typu (napr. refund =
        // príjem pod výdavkovou kategóriou). Vtedy použijeme kategóriu podľa názvu.
        $catMap = [];
        $catByName = [];
        foreach ($user->categories()->get(['id', 'name', 'type']) as $c) {
            $catMap[$c->name.'|'.$c->type] = $c->id;
            $catByName[$c->name] = $c->id;
        }
        $resolveCat = fn (string $name, string $type) => $catMap[$name.'|'.$type] ?? $catByName[$name] ?? null;

        // over, či sa všetky použité kategórie dajú namapovať
        $missing = [];
        foreach ($data['items'] as $it) {
            if (($it['t'] ?? '') === 'transfer') {
                continue;
            }
            if ($resolveCat($it['category'] ?? '', $it['t']) === null) {
                $missing[($it['category'] ?? '').'|'.$it['t']] = true;
            }
        }
        if ($missing) {
            $this->error('Nenamapované kategórie (názov|typ): '.implode(', ', array_keys($missing)));

            return self::FAILURE;
        }

        $palette = config('gros.palette');

        DB::transaction(function () use ($user, $data, $resolveCat, $palette) {
            // --- Účty ---
            $accIds = [];
            $i = 0;
            foreach ($data['accounts'] as $a) {
                $acc = $user->accounts()->firstOrCreate(
                    ['name' => $a['name']],
                    ['type' => 'Bežný účet', 'balance' => $a['balance'], 'color' => $palette[$i % count($palette)]],
                );
                $acc->update(['balance' => $a['balance']]);
                $accIds[$a['name']] = $acc->id;
                $i++;
            }

            // --- Transakcie (bulk insert po dávkach) ---
            $now = now();
            $rows = [];
            foreach ($data['items'] as $it) {
                $isTransfer = ($it['t'] ?? '') === 'transfer';
                $rows[] = [
                    'user_id' => $user->id,
                    'account_id' => $isTransfer ? $accIds[$it['from']] : $accIds[$it['account']],
                    'to_account_id' => $isTransfer ? $accIds[$it['to']] : null,
                    'category_id' => $isTransfer ? null : $resolveCat($it['category'], $it['t']),
                    'type' => $isTransfer ? 'transfer' : $it['t'],
                    'amount' => round($it['amount'], 2),
                    'date' => $it['date'],
                    'note' => $it['note'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('transactions')->insert($chunk);
            }
        });

        $this->info('Účtov: '.count($data['accounts']).' | transakcií: '.count($data['items']));
        $this->info('Hotovo. Zostatky nastavené podľa netto; transakcie vložené ako história.');

        return self::SUCCESS;
    }
}
