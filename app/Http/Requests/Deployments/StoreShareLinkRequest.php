<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'expires_in_days' => ['required', 'integer', 'min:1'],
        ];
    }
}
