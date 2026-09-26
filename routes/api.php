<?php

use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Settings\PreferenceController;
use App\Http\Middleware\ArraySession;
use App\Http\Middleware\InertiaAsApi;
use App\Http\Middleware\ProcessDuePayments;
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
Route::prefix('v1')->name('api.')->middleware([ArraySession::class, StartSession::class])->group(function () {
    Route::post('login', [TokenController::class, 'store'])->middleware('throttle:10,1')->name('login');

    Route::middleware(['auth:sanctum', ProcessDuePayments::class, InertiaAsApi::class])->group(function () {
        Route::post('logout', [TokenController::class, 'destroy'])->name('logout');

        Route::get('settings/preferences', [PreferenceController::class, 'edit'])->name('preferences.edit');
        Route::put('settings/preferences', [PreferenceController::class, 'update'])->name('preferences.update');
        Route::put('settings/plan', [PreferenceController::class, 'updatePlan'])->name('preferences.plan');

        require __DIR__.'/gros.php';
    });
});
