<?php

namespace App\Http\Requests\Api\App;

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
            'model' => ['required', 'string', 'max:64'],
            'os_version' => ['required', 'string', 'max:32'],
            'app_version' => ['required', 'string', 'max:32'],
        ];
    }
}
