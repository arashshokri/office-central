<?php
namespace App\Providers;
use Illuminate\Cache\RateLimiting\Limit; use Illuminate\Http\Request; use Illuminate\Support\Facades\{RateLimiter,URL}; use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider { public function register():void{} public function boot():void{if(str_starts_with((string)config('app.url'),'https://'))URL::forceScheme('https');RateLimiter::for('agent',fn(Request $r)=>Limit::perMinute(60)->by($r->ip()));RateLimiter::for('downloads',fn(Request $r)=>Limit::perMinute(20)->by($r->ip()));} }
