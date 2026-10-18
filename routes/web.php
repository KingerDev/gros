<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : Inertia::render('Welcome');
})->name('home');

// verejné stránky pre App Store (bez prihlásenia)
Route::view('privacy', 'legal.privacy')->name('privacy');
Route::view('support', 'legal.support')->name('support');

Route::middleware(['auth', 'verified'])->group(function () {
    require __DIR__.'/gros.php';
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
