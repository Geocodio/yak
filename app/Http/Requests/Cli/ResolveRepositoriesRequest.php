<?php

namespace App\Http\Requests\Cli;

use Illuminate\Foundation\Http\FormRequest;

class ResolveRepositoriesRequest extends FormRequest
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
            'remotes' => ['required', 'array', 'min:1', 'max:20'],
            'remotes.*' => ['required', 'string', 'max:500'],
        ];
    }
}
