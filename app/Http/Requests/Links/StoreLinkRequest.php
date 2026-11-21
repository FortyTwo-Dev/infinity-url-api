<?php

declare(strict_types=1);

namespace App\Http\Requests\Links;

use App\Models\Link;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Link::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination' => [
                'required',
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
            'slug' => ['nullable', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
