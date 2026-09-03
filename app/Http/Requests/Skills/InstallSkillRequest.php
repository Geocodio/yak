<?php

namespace App\Http\Requests\Skills;

use Illuminate\Foundation\Http\FormRequest;

class InstallSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'url' => ['nullable', 'string', 'min:3', 'required_without:name'],
            'name' => ['nullable', 'string', 'min:1', 'required_without:url'],
            'marketplace' => ['nullable', 'string'],
        ];
    }
}
