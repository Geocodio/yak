<?php

namespace App\Http\Requests\Repositories;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApprovalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'level' => ['required', 'integer', 'between:1,5'],
            'mode' => ['required', Rule::in(['shadow', 'enforce'])],
        ];
    }
}
