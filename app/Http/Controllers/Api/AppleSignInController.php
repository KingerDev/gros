<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppleIdentity;
use App\Support\DefaultCategories;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sign in with Apple. Appka pošle identity token z iOS, server ho overí
 * u Apple a nájde (alebo založí) používateľa:
 *  1. podľa Apple ID (sub),
 *  2. podľa e-mailu z tokenu — existujúci účet sa prepojí s Apple ID,
 *  3. inak nový účet bez hesla.
 */
class AppleSignInController extends Controller
{
    public function __invoke(Request $request, AppleIdentity $apple): JsonResponse
    {
        $request->validate([
            'identity_token' => ['required', 'string', 'max:10000'],
            'authorization_code' => ['nullable', 'string', 'max:1000'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $identity = $apple->verify($request->string('identity_token')->toString());
        } catch (Throwable $e) {
            Log::info('Apple: neplatný identity token', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['identity_token' => __('gros.auth.apple_invalid')]);
        }

        [$user, $created] = DB::transaction(fn () => $this->findOrCreate($identity, $request));

        if ($created) {
            DefaultCategories::seed($user);
            event(new Registered($user));
        }

        // refresh token treba na revoke pri zmazaní účtu (len ak je nastavený Apple kľúč)
        if ($request->filled('authorization_code') && ($refresh = $apple->refreshToken($request->string('authorization_code')->toString()))) {
            $user->forceFill(['apple_refresh_token' => $refresh])->save();
        }

        $device = $request->string('device_name')->limit(100, '')->toString() ?: 'Groš app';

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => $user->fresh(),
            'created' => $created,
        ]);
    }

    /**
     * @param  array{sub: string, email: ?string}  $identity
     * @return array{0: User, 1: bool}
     */
    protected function findOrCreate(array $identity, Request $request): array
    {
        if ($user = User::where('apple_id', $identity['sub'])->first()) {
            return [$user, false];
        }

        if ($identity['email'] === null) {
            // Apple posiela e-mail len pri prvom prihlásení — bez neho účet nezaložíme
            throw ValidationException::withMessages(['identity_token' => __('gros.auth.apple_no_email')]);
        }

        if ($user = User::where('email', $identity['email'])->first()) {
            $user->forceFill(['apple_id' => $identity['sub']])->save();

            return [$user, false];
        }

        $name = trim((string) $request->input('name'))
            ?: (Str::before($identity['email'], '@') ?: __('gros.auth.default_name'));

        $user = User::create([
            'name' => Str::limit($name, 255, ''),
            'email' => $identity['email'],
            'password' => null, // len cez Apple — has_password = false
            'locale' => app()->getLocale(),
        ]);
        $user->forceFill(['apple_id' => $identity['sub'], 'email_verified_at' => now()])->save();

        return [$user, true];
    }
}
