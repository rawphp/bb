<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\RedeemSignInCode;
use App\Http\Requests\Auth\CodeSignInRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class CodeSignInController
{
    public function __invoke(CodeSignInRequest $request, RedeemSignInCode $redeem): Response
    {
        Auth::login($redeem(
            $request->string('email')->value(),
            $request->string('code')->value(),
        ));
        $request->session()->regenerate();

        return response()->noContent();
    }
}
