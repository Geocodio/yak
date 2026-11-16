<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

class ListThreadEventsRequest extends FormRequest
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
            'after' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function afterSequence(): int
    {
        return (int) ($this->validated('after') ?? 0);
    }

    public function eventLimit(): int
    {
        return (int) ($this->validated('limit') ?? 500);
    }
}
