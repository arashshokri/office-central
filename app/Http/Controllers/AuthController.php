<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['active'] = true;

        if (! Auth::validate($credentials)) {
            return back()->withErrors(['email' => __('ui.invalid_credentials')])->onlyInput('email');
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();
        if ($user->two_factor_confirmed_at) {
            $request->session()->regenerateToken();
            $request->session()->put([
                'login.user_id' => $user->id,
                'login.remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        app()->setLocale($user->locale);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function locale(Request $request, string $locale)
    {
        abort_unless(in_array($locale, ['fa', 'en']), 404);
        if ($request->user()) {
            $request->user()->update(['locale' => $locale]);
        }
        session(['locale' => $locale]);

        return back();
    }
}
