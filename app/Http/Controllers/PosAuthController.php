<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PosAuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->only(['name', 'email']),
            'csrf_token' => $request->session()->token(),
            'cart_scope' => $request->user() ? hash('sha256', 'pos-cart:'.$request->session()->token()) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $credentials['email'] = strtolower($credentials['email']);
        if (strlen($credentials['password']) > 72 || !Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        $request->session()->regenerate();

        return $this->session($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->session($request);
    }
}
