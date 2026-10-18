<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletion;
use App\Services\AppleIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Zmazanie účtu z appky. Účet s heslom ho potvrdí heslom, účet len cez Apple
 * čerstvým prihlásením cez Apple (rovnaké Apple ID) — samotný token nestačí.
 */
class AccountDeletionController extends Controller
{
    public function __invoke(Request $request, AccountDeletion $deletion, AppleIdentity $apple): JsonResponse
    {
        $user = $request->user();

        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($request->string('password')->toString(), $user->password)) {
                throw ValidationException::withMessages(['password' => __('gros.auth.wrong_password')]);
            }
        } else {
            $request->validate(['identity_token' => ['required', 'string', 'max:10000']]);

            try {
                $sub = $apple->verify($request->string('identity_token')->toString())['sub'];
            } catch (Throwable) {
                $sub = null;
            }
            if ($sub === null || $sub !== $user->apple_id) {
                throw ValidationException::withMessages(['identity_token' => __('gros.auth.apple_confirm')]);
            }
        }

        $deletion->delete($user);

        return response()->json(['ok' => true]);
    }
}
