<?php

namespace App\Http\Requests\Repositories;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['generate', 'approve', 'import'])],
            'version' => ['required_if:action,approve', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reviewed' => ['accepted_if:action,approve'],
            'content' => ['required_if:action,import', 'string', 'json', 'max:1048576'],
        ];
    }
}
