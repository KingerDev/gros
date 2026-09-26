<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobilná appka volá tie isté controllery ako web — tento middleware z ich
 * odpovedí robí čisté JSON API:
 *
 *  - Inertia stránka → jej props (+ zdieľané dáta ako kategórie a nastavenia,
 *    ktoré web dostáva cez HandleInertiaRequests),
 *  - presmerovanie po akcii → {ok, message},
 *  - chyba validácie → štandardná 422 s `errors` (vďaka Accept: application/json).
 *
 * Vďaka tomu appka vidí presne tie isté čísla ako web a nová funkcia na webe
 * je hneď dostupná aj cez API.
 */
class InertiaAsApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof RedirectResponse) {
            $error = $request->session()->get('error');

            return response()->json([
                'ok' => $error === null,
                'message' => $error ?? $request->session()->get('success'),
            ]);
        }

        if ($response instanceof JsonResponse && $response->headers->get('X-Inertia') === 'true') {
            $page = $response->getData(true);

            $shared = $this->shared($request);

            // Stránka môže mať vlastný prop s rovnakým menom (napr. `events` v Analýzach
            // sú len udalosti obdobia) — pod `shared` sú preto zdieľané dáta vždy nezmenené.
            return response()->json([
                ...$shared,
                ...($page['props'] ?? []),
                'shared' => $shared,
            ]);
        }

        return $response;
    }

    /** To, čo web zdieľa na každej stránke — bez flash správ a chýb zo session. */
    protected function shared(Request $request): array
    {
        $shared = app(HandleInertiaRequests::class)->share($request);

        $keep = ['auth', 'settings', 'summary', 'categories', 'recentCategoryIds', 'events', 'catalog'];

        return collect($shared)
            ->only($keep)
            ->map(fn ($v) => $v instanceof Closure ? $v() : $v)
            ->all();
    }
}
