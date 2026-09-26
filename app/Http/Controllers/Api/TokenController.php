<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Prihlásenie mobilnej appky: e-mail + heslo → token (Sanctum). */
class TokenController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        // to isté overenie ako na webe, vrátane obmedzenia pokusov
        $request->authenticate();

        $user = Auth::user();
        $device = $request->string('device_name')->limit(100, '')->toString() ?: 'Groš app';

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => $user,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
