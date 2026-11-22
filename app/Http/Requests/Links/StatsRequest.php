<?php

declare(strict_types=1);

namespace App\Http\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

final class StatsRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'interval' => ['sometimes', 'string', 'in:day,week'],
        ];
    }
}
