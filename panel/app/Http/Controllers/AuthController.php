<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function show()
    {
        return Auth::check() ? redirect('/') : view('login');
    }

    public function login(Request $request)
    {
        $email = Str::lower(self::str($request->input('email')));
        $password = (string) $request->input('password', '');
        $key = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $sec = RateLimiter::availableIn($key);
            return back()->withInput(['email' => $email])->with('error', "Çok fazla hatalı deneme. {$sec} saniye sonra tekrar deneyin.");
        }
        if ($email === '' || $password === '') {
            return back()->withInput(['email' => $email])->with('error', 'E-posta ve şifre giriniz.');
        }
        if (! Auth::attempt(['email' => $email, 'password' => $password], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            return back()->withInput(['email' => $email])->with('error', 'E-posta veya şifre hatalı.');
        }
        $user = Auth::user();
        if (! $user->is_active) {
            Auth::logout();
            return back()->withInput(['email' => $email])->with('error', 'Hesabınız pasif durumda. Yöneticinize başvurun.');
        }
        if (! $user->firstPage()) {
            Auth::logout();
            return back()->withInput(['email' => $email])->with('error', 'Hesabınıza henüz menü yetkisi tanımlanmamış. Yöneticinize başvurun.');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended($user->firstPage());
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/giris');
    }
}
