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
        if ($request->is('up', 'login', 'logout', 'license', 'license/reactivate', 'settings/system-update', 'settings/system-update/*')) { return $next($request); }
        if (app(OfficeLicense::class)->maintenance()) {
            if ($request->expectsJson() || $request->is('api/*', 'internal/*')) { return response()->json(['code' => 'OFFICE_UPDATE_MAINTENANCE', 'message' => 'سامانه در حال بروزرسانی است.'], 503)->header('Retry-After', '5'); }
            return response()->view('office-agent.locked', ['message' => 'سامانه در حال بروزرسانی است. برای پیگیری وضعیت به تنظیمات بروزرسانی مراجعه کنید.', 'updating' => true], 503)->header('Retry-After', '5');
        }
        $decision = app(OfficeLicense::class)->decision();
        if ($decision['allowed']) { return $next($request); }
        if ($request->expectsJson() || $request->is('api/*', 'internal/*')) {
            return response()->json(['code' => 'OFFICE_LICENSE_LOCKED', 'message' => $decision['message']], 423);
        }
        return response()->view('office-agent.locked', ['message' => $decision['message']], 423);
    }
}
