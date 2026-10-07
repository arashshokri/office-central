<?php

namespace App\Http\Middleware;

use App\Services\AgentProtocol;
use App\Services\ApiResponse;
use Closure;
use Illuminate\Http\Request;

final class VerifyAgentDevice
{
    public function handle(Request $request, Closure $next)
    {
        $installation = $request->attributes->get('installation');
        if (! $installation?->device_public_key || ! app(AgentProtocol::class)->verifyRequest($request, $installation->device_public_key)) {
            return ApiResponse::error('DEVICE_SIGNATURE_INVALID', 'A valid device signature is required.', 401);
        }

        return $next($request);
    }
}
