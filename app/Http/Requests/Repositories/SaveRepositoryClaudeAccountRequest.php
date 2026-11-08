<?php

namespace App\Http\Requests\Repositories;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRepositoryClaudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $isUnattended = $this->routeIs('repos.unattended-claude-account.update');

        return [
            'claude_account_id' => ['nullable', 'integer', Rule::exists('claude_accounts', 'id')->where(
                fn ($query) => $isUnattended
                    ? $query->whereNull('owner_user_id')
                    : $query->whereNull('owner_user_id')->orWhere('owner_user_id', $this->user()?->id),
            )],
            'model' => ['nullable', 'string', Rule::in(config('yak.threads.models'))],
        ];
    }
}
