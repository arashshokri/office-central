<?php
namespace App\Http\Middleware;
use App\Models\Installation; use App\Services\ApiResponse; use Closure; use Illuminate\Http\Request;
class AuthenticateInstallation { public function handle(Request $request,Closure $next){$token=$request->bearerToken();if(!$token||!($installation=Installation::with(['license.product','release'])->where('installation_token_hash',hash('sha256',$token))->first()))return ApiResponse::error('INVALID_TOKEN','Installation credential is invalid.',401);$request->attributes->set('installation',$installation);return $next($request);} }
