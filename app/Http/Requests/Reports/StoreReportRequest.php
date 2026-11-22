<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReportRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:2048'],
            'category' => ['required', 'string', 'in:phishing,malware,other'],
            'details' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email', 'max:255'],
            // Honeypot: invisible, must stay empty. A filled field is a bot.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }
}
