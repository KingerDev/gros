<?php

use App\Http\Controllers\Api\AccountDeletionController;
use App\Http\Controllers\Api\AppleSignInController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Settings\PreferenceController;
use App\Http\Middleware\ArraySession;
use App\Http\Middleware\InertiaAsApi;
use App\Http\Middleware\ProcessDuePayments;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::post('/rings', function (Request $r) {
    $done = $r->boolean('rings_done');
    Cache::put('rings_done', $done, now()->endOfDay());
    Cache::put('rings_updated_at', now()->toIso8601String(), now()->endOfDay());

    return response()->json(['ok' => true, 'rings_done' => $done]);
});

Route::get('/rings', function (Request $r) {
    return response()->json([
        'rings_done' => Cache::get('rings_done', false),
        'updated_at' => Cache::get('rings_updated_at'),
    ]);
});

/*
| API pre mobilnú appku. Beží nad tými istými controllermi ako web
| (routes/gros.php); InertiaAsApi z ich odpovedí robí JSON.
*/
Route::prefix('v1')->name('api.')->middleware([ArraySession::class, StartSession::class, SetApiLocale::class])->group(function () {
    Route::post('login', [TokenController::class, 'store'])->middleware('throttle:10,1')->name('login');
    Route::post('register', RegisterController::class)->middleware('throttle:10,1')->name('register');
    Route::post('auth/apple', AppleSignInController::class)->middleware('throttle:10,1')->name('auth.apple');
    Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:5,1')->name('forgot-password');

    // zmazanie účtu — čisté JSON, bez Inertia vrstvy a spracovania platieb
    Route::delete('account', AccountDeletionController::class)->middleware(['auth:sanctum', 'throttle:5,1'])->name('account.destroy');

    Route::middleware(['auth:sanctum', ProcessDuePayments::class, InertiaAsApi::class])->group(function () {
        Route::post('logout', [TokenController::class, 'destroy'])->name('logout');

        // push notifikácie (týždenné zhrnutie) — registrácia zariadenia
        Route::post('push-token', [PushTokenController::class, 'store'])->name('push-token.store');
        Route::delete('push-token', [PushTokenController::class, 'destroy'])->name('push-token.destroy');

        Route::get('settings/preferences', [PreferenceController::class, 'edit'])->name('preferences.edit');
        Route::put('settings/preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::put('settings/plan', [PreferenceController::class, 'updatePlan'])->name('preferences.plan');

        require __DIR__.'/gros.php';
    });
});
