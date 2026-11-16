<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDeviceRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
        ];
    }
}
