<?php

use Illuminate\Http\Request;
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