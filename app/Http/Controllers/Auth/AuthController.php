<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Límite de intentos fallidos por correo + IP (mismo criterio que los
        // starter kits de Laravel): frena fuerza bruta sin bloquear a otros
        // usuarios de la misma red. Un login exitoso reinicia el contador.
        $throttleKey = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Demasiados intentos. Intenta de nuevo en {$seconds} segundos.",
            ], 429)->header('Retry-After', $seconds);
        }

        if (! Auth::attempt($request->only('email', 'password'))) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
