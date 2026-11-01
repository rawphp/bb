<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;

final class ForgotPasswordController
{
    public function __invoke(EmailRequest $request): Response
    {
        // The status is ignored on purpose: the answer must not reveal which emails have accounts.
        Password::sendResetLink(['email' => $request->string('email')->value()]);

        return response()->noContent();
    }
}
