<?php

namespace App\Http\Requests\Cli;

use Illuminate\Foundation\Http\FormRequest;

class StartDeviceFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'os' => ['required', 'in:darwin,linux'],
            'arch' => ['required', 'in:arm64,x86_64'],
            'cli_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
