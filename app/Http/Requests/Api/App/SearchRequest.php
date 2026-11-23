<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
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
        return ['q' => ['required', 'string', 'min:2', 'max:100']];
    }

    public function searchText(): string
    {
        return (string) $this->validated('q');
    }
}
