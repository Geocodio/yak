<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

class SearchThreadFilesRequest extends FormRequest
{
    /** Searching the repository is for everyone who can see the thread; the thread middleware hides it from others. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:repo'],
            'q' => ['required', 'string', 'min:1', 'max:200', 'not_regex:/[\x00-\x1F]/'],
        ];
    }
}
