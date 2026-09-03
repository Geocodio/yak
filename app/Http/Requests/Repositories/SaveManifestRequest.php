<?php

namespace App\Http\Requests\Repositories;

use Illuminate\Foundation\Http\FormRequest;

class SaveManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'health_probe_path' => ['required', 'string', 'starts_with:/'],
            'cold_start' => ['nullable', 'string'],
            'checkout_refresh' => ['nullable', 'string'],
            'wake_timeout_seconds' => ['integer', 'min:1'],
        ];
    }
}
