<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
class RequireRole { public function handle(Request $request,Closure $next,string ...$roles){abort_unless($request->user()&&$request->user()->active&&in_array($request->user()->role,$roles,true),403);return $next($request);} }
