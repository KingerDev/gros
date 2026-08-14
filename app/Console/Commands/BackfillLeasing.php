<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spätné doplnenie lízingu a mzdy, ktorá bola doteraz zadávaná už zníženej
 * o splátku.
 *
 * Kým sa príjem zadával „v hlave znížený", model splátku odrátaval dvakrát:
 * raz cez nižší príjem a druhý raz cez splátku zo stránky Úvery. Toto to
 * rozpletie — pridá chýbajúci príjem aj chýbajúci výdavok, takže obe strany
 * sedia a zostatok účtu sa nepohne.
 *
 * Bez --apply len ukáže, čo by spravil. Doplnené položky sú označené
 * poznámkou, takže sa dajú kedykoľvek nájsť aj vrátiť cez --revert.
 */
class BackfillLeasing extends Command
{
    protected $signature = 'gros:backfill-leasing
        {--user= : Id používateľa (inak prvý)}
        {--from=2026-05 : Prvý mesiac, YYYY-MM}
        {--to= : Posledný mesiac, YYYY-MM (inak aktuálny)}
        {--amount=400 : Mesačná splátka}
        {--apply : Naozaj zapísať}
        {--revert : Zmazať skôr doplnené položky}';

    protected $description = 'Spätne doplní splátky lízingu a mzdu, ktorá bola zadávaná už zníženú o splátku';

    /** Podľa tejto poznámky sa doplnené položky dajú nájsť aj vrátiť. */
    public const TAG = '[auto] spätné doplnenie lízingu';

    public function handle(): int
    {
        $user = $this->option('user')
            ? User::findOrFail($this->option('user'))
            : User::firstOrFail();

        if ($this->option('revert')) {
            return $this->revert($user);
        }

        $loan = $user->loans()->where('kind', 'owe')->where('payment', '>', 0)->first();
        if (! $loan) {
            $this->error('Používateľ nemá žiadny úver so splátkou.');

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        $from = CarbonImmutable::parse($this->option('from').'-01')->startOfMonth();
        $to = CarbonImmutable::parse(($this->option('to') ?: CarbonImmutable::today()->format('Y-m')).'-01')->startOfMonth();

        $accountId = $loan->account_id ?? $user->accounts()->value('id');
        $categoryId = $loan->category_id;

        $plan = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $ym = $m->format('Y-m');

            // splátka mohla byť už zaúčtovaná automatikou — vtedy ju nepridávame
            $hasPayment = $user->transactions()
                ->where('type', 'expense')
                ->where(fn ($q) => $q->where('source', 'loan')->orWhere('category_id', $categoryId))
                ->whereDate('date', '>=', $m->toDateString())
                ->whereDate('date', '<=', $m->endOfMonth()->toDateString())
                ->exists();

            $plan[] = [
                'ym' => $ym,
                'date' => $m->addDays(min(4, $m->daysInMonth - 1))->toDateString(),
                'income' => $amount,
                'expense' => $hasPayment ? 0.0 : $amount,
                'note' => $hasPayment ? 'splátka už zaúčtovaná — dopĺňa sa len mzda' : 'dopĺňa sa mzda aj splátka',
            ];
        }

        $this->line('');
        $this->info('Používateľ: '.$user->email.'   úver: '.$loan->name.'   účet #'.$accountId.'   kategória #'.$categoryId);
        $this->table(
            ['Mesiac', 'Dátum', 'Príjem +', 'Výdavok −', 'Zmena zostatku', 'Poznámka'],
            array_map(fn ($r) => [
                $r['ym'], $r['date'],
                number_format($r['income'], 2),
                $r['expense'] ? number_format($r['expense'], 2) : '—',
                number_format($r['income'] - $r['expense'], 2),
                $r['note'],
            ], $plan)
        );

        $balanceShift = array_sum(array_map(fn ($r) => $r['income'] - $r['expense'], $plan));
        $this->line('');
        $this->info('Spolu doplnený príjem: '.number_format(array_sum(array_column($plan, 'income')), 2).' €');
        $this->info('Spolu doplnený výdavok: '.number_format(array_sum(array_column($plan, 'expense')), 2).' €');
        $this->warn('Zostatok účtu #'.$accountId.' sa zmení o '.number_format($balanceShift, 2).' €'
            .($balanceShift > 0 ? '  (mesiace, kde splátka už bola zaúčtovaná, ale mzda bola zadaná znížená)' : ''));

        if (! $this->option('apply')) {
            $this->line('');
            $this->comment('Nič sa nezapísalo. Spusti s --apply, keď to sedí.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $plan, $accountId, $categoryId, $loan) {
            foreach ($plan as $r) {
                Transaction::create([
                    'user_id' => $user->id, 'account_id' => $accountId, 'category_id' => null,
                    'type' => 'income', 'amount' => $r['income'], 'date' => $r['date'],
                    'note' => self::TAG.' — mzda pred splátkou',
                ]);
                Account::whereKey($accountId)->increment('balance', $r['income']);

                if ($r['expense'] > 0) {
                    // Doplnená splátka je rovnaký záväzok ako tá, ktorú zaúčtuje
                    // automatika — musí niesť rovnaký source, inak ju analýzy
                    // berú ako voľný výdavok a rezerva ju počíta dvakrát.
                    Transaction::create([
                        'user_id' => $user->id, 'account_id' => $accountId, 'category_id' => $categoryId,
                        'type' => 'expense', 'amount' => $r['expense'], 'date' => $r['date'],
                        'note' => self::TAG.' — splátka lízingu',
                        'source' => 'loan', 'source_id' => $loan->id,
                    ]);
                    Account::whereKey($accountId)->decrement('balance', $r['expense']);
                }
            }
        });

        $this->line('');
        $this->info('Zapísané. Vrátiť sa dá cez: php artisan gros:backfill-leasing --revert');

        return self::SUCCESS;
    }

    protected function revert(User $user): int
    {
        $rows = $user->transactions()->where('note', 'like', self::TAG.'%')->get();

        if ($rows->isEmpty()) {
            $this->comment('Niet čo vracať.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $t) {
                $t->type === 'income'
                    ? Account::whereKey($t->account_id)->decrement('balance', (float) $t->amount)
                    : Account::whereKey($t->account_id)->increment('balance', (float) $t->amount);
                $t->delete();
            }
        });

        $this->info('Zmazaných '.$rows->count().' doplnených položiek, zostatky vrátené.');

        return self::SUCCESS;
    }
}
