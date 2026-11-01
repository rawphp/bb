<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalisesEmail;
use Illuminate\Foundation\Http\FormRequest;

final class CodeSignInRequest extends FormRequest
{
    use NormalisesEmail;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
