<?php

namespace App\Http\Requests\Threads;

use App\Enums\RepositoryRole;
use App\Models\TaskAttachment;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'repo' => ['required', 'string', Rule::exists('repositories', 'slug')->where('is_active', true)],
            'mode' => ['required', 'in:fix,research'],
            'repositories' => ['nullable', 'array'],
            'repositories.*.repo' => ['required', 'string', 'distinct', 'different:repo'],
            'repositories.*.role' => ['required', Rule::in([RepositoryRole::Changes->value, RepositoryRole::Context->value])],
            'description' => ['required', 'string', 'min:3', 'max:10000'],
            'claude_account_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', ClaudeModelCatalog::rule()],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
