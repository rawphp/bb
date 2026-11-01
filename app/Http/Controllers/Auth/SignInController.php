<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\SignInRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SignInController
{
    public function __invoke(SignInRequest $request): Response
    {
        if (! Auth::attempt($request->validated(), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'That email and password do not match an account.',
            ]);
        }

        // Belt and braces: SessionGuard::login() already regenerated the session.
        $request->session()->regenerate();

        return response()->noContent();
    }
}
