<?php

use App\Http\Requests\Auth\CodeSignInRequest;
use App\Http\Requests\Auth\EmailRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Foundation\Http\FormRequest;

// MySQL's case-insensitive collation finds the account either way, so the feature tests
// cannot see this. The lower-case form still matters to the per-address limits and to
// any database that compares case.
it('ACC-001 hands the actions the email in the form accounts store', function (string $class, array $body) {
    /** @var FormRequest $request */
    $request = $class::create('/', 'POST', ['email' => 'Sam@Example.COM', ...$body]);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    expect($request->validated('email'))->toBe('sam@example.com');
})->with([
    'code' => [EmailRequest::class, []],
    'verify' => [CodeSignInRequest::class, ['code' => '123456']],
    'reset' => [ResetPasswordRequest::class, ['token' => 't', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123']],
]);
