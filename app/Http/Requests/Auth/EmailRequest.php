<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalisesEmail;
use Illuminate\Foundation\Http\FormRequest;

/** An email to send a sign-in code or a reset link to. */
final class EmailRequest extends FormRequest
{
    use NormalisesEmail;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
