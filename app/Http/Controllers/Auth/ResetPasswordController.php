<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\ResetUserPassword;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\Response;

final class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request, ResetUserPassword $reset): Response
    {
        $reset([
            'token' => $request->string('token')->value(),
            'email' => $request->string('email')->value(),
            'password' => $request->string('password')->value(),
            'password_confirmation' => $request->string('password_confirmation')->value(),
        ]);

        return response()->noContent();
    }
}
