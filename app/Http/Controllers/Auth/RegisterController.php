<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\RegisterUser;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class RegisterController
{
    public function __invoke(RegisterRequest $request, RegisterUser $register): Response
    {
        Auth::login($register(
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->string('password')->value(),
        ));
        // Belt and braces: SessionGuard::login() already regenerated the session.
        $request->session()->regenerate();

        return response()->noContent();
    }
}
