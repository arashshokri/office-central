<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request)
    {
        abort_unless($request->session()->has('login.user_id'), 404);

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TotpService $totp)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = User::findOrFail($request->session()->get('login.user_id'));
        abort_unless($user->active && $user->two_factor_confirmed_at, 403);

        $validTotp = $totp->verify((string) $user->two_factor_secret, $data['code']);
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        $recoveryIndex = null;

        if (! $validTotp) {
            foreach ($recoveryCodes as $index => $hash) {
                if (Hash::check(strtoupper(trim($data['code'])), $hash)) {
                    $recoveryIndex = $index;
                    break;
                }
            }
        }

        if (! $validTotp && $recoveryIndex === null) {
            return back()->withErrors(['code' => __('ui.invalid_two_factor_code')]);
        }

        if ($recoveryIndex !== null) {
            unset($recoveryCodes[$recoveryIndex]);
            $user->update(['two_factor_recovery_codes' => array_values($recoveryCodes)]);
        }

        Auth::login($user, (bool) $request->session()->pull('login.remember', false));
        $request->session()->forget('login.user_id');
        $request->session()->regenerate();
        app()->setLocale($user->locale);

        return redirect()->intended(route('dashboard'));
    }
}
