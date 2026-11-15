<?php

namespace App\Http\Requests\Api\App\Push;

use Illuminate\Foundation\Http\FormRequest;

class StorePushTokenRequest extends FormRequest
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
        return [
            'token' => ['required', 'string', 'min:64', 'max:200', 'regex:/^[0-9a-fA-F]+$/'],
            'environment' => ['required', 'string', 'in:sandbox,production'],
        ];
    }
}
