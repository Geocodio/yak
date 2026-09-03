<?php

namespace App\Http\Requests\Deployments;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHibernationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'long_lived' => ['required', 'boolean'],
            'timeout' => ['nullable', 'string'],
        ];
    }
}
