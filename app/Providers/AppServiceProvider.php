<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // prihlásené API a web: na používateľa, aby jeden účet nezahltil server
        RateLimiter::for('gros', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));
    }
}
