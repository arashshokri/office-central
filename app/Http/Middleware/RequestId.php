<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Illuminate\Support\Str;
class RequestId { public function handle(Request $request,Closure $next){$id=$request->headers->get('X-Request-ID')?:Str::uuid()->toString();$request->attributes->set('request_id',$id);$response=$next($request);$response->headers->set('X-Request-ID',$id);return $response;} }
