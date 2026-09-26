<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controllery Groša počítajú so session (flash správy, zvolené obdobie).
 * API sa overuje tokenom, takže session tu žije len počas jednej požiadavky
 * a nikam sa neukladá — appka si obdobie posiela v každej požiadavke sama.
 */
class ArraySession
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.driver' => 'array']);

        return $next($request);
    }
}
