<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailRequest;
use App\Jobs\EmailPasswordResetLink;
use Illuminate\Http\Response;

final class ForgotPasswordController
{
    public function __invoke(EmailRequest $request): Response
    {
        EmailPasswordResetLink::dispatch($request->string('email')->value());

        return response()->noContent();
    }
}
