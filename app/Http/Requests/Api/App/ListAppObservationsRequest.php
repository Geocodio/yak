<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

class ListAppObservationsRequest extends FormRequest
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
            'repo' => ['nullable', 'string', 'max:255'],
            'outcome' => ['nullable', 'in:acted,declined'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
