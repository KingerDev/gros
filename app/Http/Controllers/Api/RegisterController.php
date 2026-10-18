<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** Registrácia z mobilnej appky: rovnako ako na webe, len vráti token. */
class RegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // appka má jedno pole na heslo — bez password_confirmation
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'string', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'locale' => app()->getLocale(), // z Accept-Language (SetApiLocale)
        ]);

        DefaultCategories::seed($user);

        event(new Registered($user));

        $device = $request->string('device_name')->limit(100, '')->toString() ?: 'Groš app';

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => $user->fresh(),
        ], 201);
    }
}
