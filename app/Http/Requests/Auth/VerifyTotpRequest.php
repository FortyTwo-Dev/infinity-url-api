<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyTotpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'string', 'prohibits:recovery_code'],
            'recovery_code' => ['required_without:code', 'string', 'prohibits:code'],
        ];
    }
}
