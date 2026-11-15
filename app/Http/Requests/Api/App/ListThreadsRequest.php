<?php

namespace App\Http\Requests\Api\App;

use App\Enums\ThreadListFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListThreadsRequest extends FormRequest
{
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
            'filter' => ['nullable', Rule::enum(ThreadListFilter::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function listFilter(): ThreadListFilter
    {
        return ThreadListFilter::tryFrom((string) $this->validated('filter')) ?? ThreadListFilter::Mine;
    }

    public function searchText(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }
}
