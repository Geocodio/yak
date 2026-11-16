<?php

namespace App\Http\Requests\Api\App;

use App\DataTransferObjects\TaskListFilters;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAppTasksRequest extends FormRequest
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
            'tab' => ['nullable', 'in:tasks,reviews,setup'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'source' => ['nullable', 'string', 'max:64'],
            'repo' => ['nullable', 'string', 'max:255'],
            'pr' => ['nullable', 'in:open,merged,closed,none'],
            'sort' => ['nullable', 'in:created_at,status,source,author_name,repo'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function filters(): TaskListFilters
    {
        $search = trim((string) $this->validated('search'));

        return new TaskListFilters(
            tab: $this->validated('tab') ?? 'tasks',
            status: $this->validated('status'),
            source: $this->validated('source'),
            repo: $this->validated('repo'),
            pr: $this->validated('pr'),
            sort: $this->validated('sort') ?? 'created_at',
            direction: $this->validated('direction') ?? 'desc',
            search: $search === '' ? null : $search,
        );
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
