<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Zmazanie účtu z appky. Účet s heslom ho musí potvrdiť, účet len cez Apple nie. */
class AccountDeletionController extends Controller
{
    public function __invoke(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $user = $request->user();

        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'string']]);

            if (! Hash::check($request->string('password')->toString(), $user->password)) {
                throw ValidationException::withMessages(['password' => __('gros.auth.wrong_password')]);
            }
        }

        $deletion->delete($user);

        return response()->json(['ok' => true]);
    }
}
