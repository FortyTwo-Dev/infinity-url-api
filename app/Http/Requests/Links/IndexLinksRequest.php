<?php

declare(strict_types=1);

namespace App\Http\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

final class IndexLinksRequest extends FormRequest
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
            'domain' => ['sometimes', 'string', 'max:253'],
            'status' => ['sometimes', 'string', 'in:active,disabled,blocked'],
            'q' => ['sometimes', 'string', 'min:2'],
            'sort' => ['sometimes', 'string', 'in:created_at,-created_at'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
