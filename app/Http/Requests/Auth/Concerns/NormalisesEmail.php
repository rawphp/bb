<?php

namespace App\Http\Requests\Auth\Concerns;

use App\Domain\Account\EmailAddress;

trait NormalisesEmail
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => EmailAddress::normalise((string) $this->input('email'))]);
    }
}
