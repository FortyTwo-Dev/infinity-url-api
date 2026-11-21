<?php

declare(strict_types=1);

namespace App\Http\Requests\Links;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateLinkRequest extends FormRequest
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
            'destination' => [
                'sometimes',
                'string',
                'max:2048',
                'url:http,https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parts = parse_url((string) $value);

                    if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                        $fail('The destination must not contain credentials.');
                    }
                },
            ],
            'slug' => ['sometimes', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
        ];
    }
}
