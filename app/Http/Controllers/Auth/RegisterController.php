<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\RegisterUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

final class RegisterController
{
    public function __invoke(Request $request, RegisterUser $register): Response
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        Auth::login($register($input['name'], $input['email'], $input['password']));
        $request->session()->regenerate();

        return response()->noContent();
    }
}
