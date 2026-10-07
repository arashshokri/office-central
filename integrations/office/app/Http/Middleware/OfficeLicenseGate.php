<?php

namespace App\Http\Middleware;

use App\Services\OfficeLicense;
use Closure;
use Illuminate\Http\Request;

final class OfficeLicenseGate
{
    public function handle(Request $request, Closure $next)
    {
        // Authentication and reactivation remain accessible. Health is checked
        // independently of licensing so a copied database can be reactivated.
        if ($request->is('up', 'login', 'logout', 'license', 'license/reactivate')) { return $next($request); }
        $decision = app(OfficeLicense::class)->decision();
        if ($decision['allowed']) { return $next($request); }
        if ($request->expectsJson() || $request->is('api/*', 'internal/*')) {
            return response()->json(['code' => 'OFFICE_LICENSE_LOCKED', 'message' => $decision['message']], 423);
        }
        return response()->view('office-agent.locked', ['message' => $decision['message']], 423);
    }
}
