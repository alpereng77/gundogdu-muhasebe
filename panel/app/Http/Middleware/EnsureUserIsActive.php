<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Pasifleştirilen kullanıcının oturumu bir sonraki istekte kapatılır. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            return $request->expectsJson()
                ? response()->json(['message' => 'Hesabınız pasif durumda.'], 401)
                : redirect('/giris')->with('error', 'Hesabınız pasif durumda. Yöneticinize başvurun.');
        }
        return $next($request);
    }
}
