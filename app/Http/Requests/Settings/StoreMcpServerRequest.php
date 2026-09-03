<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', 'max:64'],
            'transport' => ['required', Rule::in(['http', 'sse', 'stdio'])],
            'target' => [
                'required',
                'string',
                'max:2000',
                Rule::when($this->input('transport') !== 'stdio', ['url']),
            ],
            'headers' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
