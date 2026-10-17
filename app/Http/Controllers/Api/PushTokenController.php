<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Registrácia zariadenia na push notifikácie (Expo Push Service). */
class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'starts_with:ExponentPushToken[,ExpoPushToken['],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        // token patrí zariadeniu — ak sa naň predtým prihlásil iný používateľ, prejde na aktuálneho
        PushToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null],
        );

        return response()->json(['ok' => true, 'message' => null]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->pushTokens()->where('token', $data['token'])->delete();

        return response()->json(['ok' => true, 'message' => null]);
    }
}
