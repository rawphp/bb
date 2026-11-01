<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\SendSignInCode;
use App\Http\Requests\Auth\EmailRequest;
use Illuminate\Http\Response;

final class SendSignInCodeController
{
    public function __invoke(EmailRequest $request, SendSignInCode $send): Response
    {
        $send($request->string('email')->value());

        return response()->noContent();
    }
}
