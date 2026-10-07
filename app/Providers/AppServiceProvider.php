<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) { URL::forceScheme('https'); }
        // Laravel prioritizes throttling before custom device authentication.
        // Each valid credential is unique to one installation; an IP ceiling
        // still protects credential lookup against invented bearer tokens.
        RateLimiter::for('installer', fn (Request $request) => [
            Limit::perMinute(600)->by('ip:'.$request->ip()),
            Limit::perMinute(120)->by('device:'.hash('sha256', $request->bearerToken() ?: $request->ip())),
        ]);
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('downloads', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
