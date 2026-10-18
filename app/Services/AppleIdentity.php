<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Sign in with Apple pre mobilnú appku:
 *
 *  - overenie identity tokenu (JWT od Apple, RS256, kľúče z JWKS),
 *  - výmena authorization_code za refresh token a jeho revoke pri zmazaní
 *    účtu (App Store to vyžaduje). Na to treba Apple kľúč (p8) — bez neho
 *    sa tieto kroky ticho preskočia.
 *
 * Samostatná trieda, aby sa v testoch dala podvrhnúť.
 */
class AppleIdentity
{
    public const ISSUER = 'https://appleid.apple.com';

    public const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    protected const CACHE_KEY = 'apple_jwks';

    /**
     * Overí identity token a vráti Apple ID (sub) a e-mail, ak ho Apple poslal.
     *
     * @return array{sub: string, email: ?string}
     *
     * @throws UnexpectedValueException neplatný, cudzí alebo expirovaný token
     */
    public function verify(string $identityToken): array
    {
        try {
            $claims = $this->decode($identityToken, $this->keys());
        } catch (UnexpectedValueException $e) {
            // Apple mohol kľúče vymeniť — skúsime ešte raz s čerstvými
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            Cache::forget(self::CACHE_KEY);
            $claims = $this->decode($identityToken, $this->keys());
        }

        $aud = (array) ($claims['aud'] ?? []);

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new UnexpectedValueException('Wrong issuer.');
        }
        if (! in_array(config('services.apple.client_id'), $aud, true)) {
            throw new UnexpectedValueException('Wrong audience.');
        }
        if (empty($claims['sub']) || ! is_string($claims['sub'])) {
            throw new UnexpectedValueException('Missing subject.');
        }

        // e-mail berieme len výslovne overený (Apple ho posiela vždy spolu s email_verified)
        $email = in_array($claims['email_verified'] ?? false, [true, 'true'], true) ? ($claims['email'] ?? null) : null;

        return [
            'sub' => $claims['sub'],
            'email' => is_string($email) && $email !== '' ? mb_strtolower($email) : null,
        ];
    }

    /** Je nastavený Apple kľúč (p8)? Bez neho nejde výmena kódu ani revoke. */
    public function configured(): bool
    {
        return filled(config('services.apple.team_id'))
            && filled(config('services.apple.key_id'))
            && filled(config('services.apple.private_key'));
    }

    /** authorization_code → refresh token (na neskorší revoke). Null, ak to nejde. */
    public function refreshToken(string $authorizationCode): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);
        } catch (Throwable $e) {
            Log::warning('Apple: výmena authorization_code zlyhala', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Apple: výmena authorization_code zlyhala', ['status' => $response->status(), 'body' => $response->json('error')]);

            return null;
        }

        return $response->json('refresh_token');
    }

    /** Zruší prepojenie appky s Apple ID. Chyby len zaloguje — zmazanie účtu nesmie zlyhať. */
    public function revoke(string $refreshToken): void
    {
        if (! $this->configured()) {
            return;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::REVOKE_URL, [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
            ]);

            if ($response->failed()) {
                Log::warning('Apple: revoke zlyhal', ['status' => $response->status(), 'body' => $response->json('error')]);
            }
        } catch (Throwable $e) {
            Log::warning('Apple: revoke zlyhal', ['error' => $e->getMessage()]);
        }
    }

    /** client_secret pre Apple = krátkodobý ES256 JWT podpísaný kľúčom p8. */
    protected function clientSecret(): string
    {
        // v .env býva p8 v jednom riadku so zalomeniami ako \n
        $key = str_replace('\n', "\n", (string) config('services.apple.private_key'));

        return JWT::encode([
            'iss' => config('services.apple.team_id'),
            'iat' => time(),
            'exp' => time() + 300,
            'aud' => self::ISSUER,
            'sub' => config('services.apple.client_id'),
        ], $key, 'ES256', config('services.apple.key_id'));
    }

    /** @return array<string, mixed> */
    protected function decode(string $token, array $keys): array
    {
        try {
            JWT::$leeway = 60;

            return (array) JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
        } catch (UnexpectedValueException $e) {
            throw $e;
        } catch (Throwable $e) {
            // DomainException, InvalidArgumentException… — pre volajúceho jedno: neplatný token
            throw new UnexpectedValueException($e->getMessage(), 0, $e);
        }
    }

    /** Verejné kľúče Apple (JWKS), cache na deň. */
    protected function keys(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            $keys = Http::acceptJson()->timeout(10)->get(self::KEYS_URL)->throw()->json();

            if (! is_array($keys['keys'] ?? null)) {
                throw new UnexpectedValueException('Apple JWKS sa nepodarilo načítať.');
            }

            return $keys;
        });
    }
}
