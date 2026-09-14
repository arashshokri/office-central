<?php
namespace App\Http\Middleware;
use App\Services\ApiResponse; use Closure; use Illuminate\Http\Request;
class RequireHttpsForAgent { public function handle(Request $request,Closure $next){if(config('office.require_https')&&!$request->secure())return ApiResponse::error('HTTPS_REQUIRED','Agent API requires HTTPS.',400);return $next($request);} }
