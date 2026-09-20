<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    public function show(Request $request, TotpService $totp)
    {
        $user = $request->user();

        return view('admin.security-settings', [
            'user' => $user,
            'provisioningUri' => $user->two_factor_secret && ! $user->two_factor_confirmed_at
                ? $totp->provisioningUri($user->two_factor_secret, $user->email)
                : null,
        ]);
    }

    public function begin(Request $request, TotpService $totp, AuditService $audit)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->update([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $audit->record('security.two_factor_started', $request->user());

        return back()->with('success', __('ui.two_factor_scan_help'));
    }

    public function confirm(Request $request, TotpService $totp, AuditService $audit)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        abort_unless($user->two_factor_secret, 422);

        if (! $totp->verify($user->two_factor_secret, $data['code'])) {
            return back()->withErrors(['code' => __('ui.invalid_two_factor_code')]);
        }

        $plainCodes = $totp->recoveryCodes();
        $user->update([
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $plainCodes),
            'two_factor_confirmed_at' => now(),
        ]);
        $audit->record('security.two_factor_enabled', $user);

        return back()->with('recovery_codes', $plainCodes)->with('success', __('ui.two_factor_enabled'));
    }

    public function disable(Request $request, AuditService $audit)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
        $audit->record('security.two_factor_disabled', $user);

        return back()->with('success', __('ui.two_factor_disabled'));
    }
}
