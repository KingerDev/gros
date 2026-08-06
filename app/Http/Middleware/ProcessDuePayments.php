<?php

namespace App\Http\Middleware;

use App\Services\RecurringPaymentService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Splatné predplatné a splátky úverov sa spracujú pri načítaní stránky —
 * bez cronu na serveri. Kontrola sú dva ľahké indexované dotazy; keď nie je
 * nič splatné, neurobí sa nič. Zámok bráni dvojitému zaúčtovaniu, keď
 * prehliadač pošle viac požiadaviek naraz.
 *
 * Naplánovaný `gros:process-payments` ostáva funkčný — ak cron existuje,
 * platby vzniknú ráno a toto potom už nemá čo robiť.
 */
class ProcessDuePayments
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $request->isMethod('GET') && ! $request->is('up')) {
            $lock = Cache::lock("gros:payments:{$user->id}", 20);

            if ($lock->get()) {
                try {
                    app(RecurringPaymentService::class)->process(null, $user);
                } finally {
                    $lock->release();
                }
            }
        }

        return $next($request);
    }
}
