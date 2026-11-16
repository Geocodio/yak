<?php

namespace App\Http\Requests\Settings;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClaudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ClaudeAccount && ($this->user()?->can('manage', $account) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var ClaudeAccount $account */
        $account = $this->route('account');

        return [
            'name' => ['sometimes', 'string', 'max:60', Rule::unique('claude_accounts', 'name')->ignore($account->id)->where(
                fn ($query) => $account->owner_user_id === null ? $query->whereNull('owner_user_id') : $query->where('owner_user_id', $account->owner_user_id),
            )],
            'default_model' => ['sometimes', 'nullable', 'string', ClaudeModelCatalog::rule()],
            'icon' => ['sometimes', Rule::enum(ClaudeAccountIcon::class)],
            'color' => ['sometimes', Rule::enum(ClaudeAccountColor::class)],
            'is_default' => ['sometimes', 'boolean'],
            'max_concurrent_turns' => ['sometimes', 'integer', 'min:1', 'max:64'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
