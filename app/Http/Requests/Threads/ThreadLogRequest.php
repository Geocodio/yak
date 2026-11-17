<?php

namespace App\Http\Requests\Threads;

use Illuminate\Foundation\Http\FormRequest;

class ThreadLogRequest extends FormRequest
{
    /**
     * A hand-edited URL never fails validation: unknown values fall back or match nothing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The filters in the shape the thread log query takes.
     *
     * @return array{status: list<string>, sources: list<string>, starters: list<string>, repo: string, pull_request: string, needs_co_owner: bool, search: string, sort: string, direction: string}
     */
    public function filters(): array
    {
        $sort = in_array($this->input('sort'), ['last_activity', 'source', 'starter', 'repo'], true) ? (string) $this->input('sort') : 'last_activity';
        $direction = in_array($this->input('direction'), ['asc', 'desc'], true) ? (string) $this->input('direction') : ($sort === 'last_activity' ? 'desc' : 'asc');

        return [
            'status' => $this->strings('status'),
            'sources' => $this->strings('sources'),
            'starters' => $this->strings('starters'),
            'repo' => $this->text('repo'),
            'pull_request' => $this->text('pull_request'),
            'needs_co_owner' => $this->boolean('needs_co_owner'),
            'search' => trim($this->text('search')),
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        return array_values(array_filter((array) $this->input($key, []), is_string(...)));
    }

    private function text(string $key): string
    {
        $value = $this->input($key, '');

        return is_string($value) ? $value : '';
    }
}
