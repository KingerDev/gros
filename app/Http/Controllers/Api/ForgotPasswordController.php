<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/** Zabudnuté heslo z appky — pošle ten istý odkaz ako web (stránka na reset je na webe). */
class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        // vždy rovnaká odpoveď — neprezradí, či účet s týmto e-mailom existuje
        return response()->json(['ok' => true, 'message' => __('gros.auth.reset_link')]);
    }
}
