<?php

declare(strict_types=1);

namespace App\Http\Requests\Tokens;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTokenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'scopes' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $allowed = (array) config('identity.api_scopes');

                    foreach ($value as $scope) {
                        if (! is_string($scope) || ! in_array($scope, $allowed, true)) {
                            $fail('One or more scopes are invalid.');

                            return;
                        }
                    }
                },
            ],
            'scopes.*' => ['string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
