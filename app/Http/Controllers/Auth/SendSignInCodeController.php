<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailRequest;
use App\Jobs\EmailSignInCode;
use Illuminate\Http\Response;

final class SendSignInCodeController
{
    public function __invoke(EmailRequest $request): Response
    {
        EmailSignInCode::dispatch($request->string('email')->value());

        return response()->noContent();
    }
}
