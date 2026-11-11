<?php

namespace App\Http\Requests\Cli;

use Illuminate\Foundation\Http\FormRequest;

class PollDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['device_code' => ['required', 'string', 'max:100']];
    }
}
