<?php

namespace App\Http\Requests\Threads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThreadLogRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', 'max:64'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', 'max:64'],
            'starters' => ['sometimes', 'array'],
            'starters.*' => ['string', 'max:255'],
            'repo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pull_request' => ['sometimes', 'nullable', Rule::in(['open', 'merged', 'closed', 'held', 'none'])],
            'needs_co_owner' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort' => ['sometimes', 'nullable', Rule::in(['last_activity', 'source', 'starter', 'repo'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * The filters in the shape the thread log query takes.
     *
     * @return array{status: list<string>, sources: list<string>, starters: list<string>, repo: string, pull_request: string, needs_co_owner: bool, search: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        $sort = (string) ($this->input('sort') ?: 'last_activity');

        return [
            'status' => array_values((array) $this->input('status', [])),
            'sources' => array_values((array) $this->input('sources', [])),
            'starters' => array_values((array) $this->input('starters', [])),
            'repo' => (string) $this->input('repo', ''),
            'pull_request' => (string) $this->input('pull_request', ''),
            'needs_co_owner' => $this->boolean('needs_co_owner'),
            'search' => trim((string) $this->input('search', '')),
            'sort' => $sort,
            'direction' => (string) ($this->input('direction') ?: ($sort === 'last_activity' ? 'desc' : 'asc')),
        ];
    }
}
