<?php

namespace App\Http\Requests\Prompts;

use Illuminate\Foundation\Http\FormRequest;

class SavePromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
        ];
    }
}
