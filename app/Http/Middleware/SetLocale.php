<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
class SetLocale { public function handle(Request $request,Closure $next){$locale=$request->user()?->locale??session('locale',config('app.locale'));app()->setLocale(in_array($locale,['fa','en'])?$locale:'en');return $next($request);} }
