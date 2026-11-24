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
            'filter' => ['nullable', Rule::enum(ThreadListFilter::class)->except([ThreadListFilter::Review])],
            'search' => ['nullable', 'string', 'max:100'],
            'sources' => ['nullable', 'array', 'max:50'],
            'sources.*' => ['string', 'max:64'],
            'starters' => ['nullable', 'array', 'max:50'],
            'starters.*' => ['string', 'max:255'],
        ];
    }

    public function listFilter(): ThreadListFilter
    {
        return ThreadListFilter::tryFrom((string) $this->validated('filter')) ?? ThreadListFilter::Mine;
    }

    /**
     * @return list<string>
     */
    public function sourceKeys(): array
    {
        return $this->validated('sources') ?? [];
    }

    /**
     * @return list<string>
     */
    public function starterKeys(): array
    {
        return $this->validated('starters') ?? [];
    }

    public function searchText(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }
}
