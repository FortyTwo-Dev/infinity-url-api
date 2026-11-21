<?php

declare(strict_types=1);

namespace App\Http\Requests\Tokens;

use Illuminate\Foundation\Http\FormRequest;

final class IndexTokensRequest extends FormRequest
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
