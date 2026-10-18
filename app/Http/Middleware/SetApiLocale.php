<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jazyk API podľa zariadenia (Accept-Language): slovenčina a čeština → 'sk',
 * všetko ostatné → 'en'. Web zostáva po slovensky (APP_LOCALE).
 *
 * Prihlásenému používateľovi sa jazyk uloží do users.locale — podľa neho
 * idú push notifikácie (mimo requestu) a východiskové kategórie.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // beží pred auth:sanctum — používateľa si zistíme sami (null bez tokenu)
        $user = $request->user('sanctum');
        $locale = self::fromHeader($request) ?? $user?->locale ?? 'en';

        app()->setLocale($locale);

        if ($user instanceof User && $user->locale !== $locale) {
            // len jazyk — nemení updated_at ani nespúšťa eventy
            $user->forceFill(['locale' => $locale]);
            $user->timestamps = false;
            $user->saveQuietly();
            $user->timestamps = true;
        }

        return $next($request);
    }

    /** Null, keď hlavička chýba (alebo je prázdna) — vtedy platí uložený jazyk používateľa. */
    public static function fromHeader(Request $request): ?string
    {
        if (trim((string) $request->header('Accept-Language')) === '') {
            return null;
        }

        $first = strtolower((string) ($request->getLanguages()[0] ?? ''));

        return str_starts_with($first, 'sk') || str_starts_with($first, 'cs') ? 'sk' : 'en';
    }
}
