<?php

namespace App\Http\Requests\Settings;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Http\Concerns\ValidatesClaudeSecretFormat;
use App\Models\ClaudeAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaudeAccountRequest extends FormRequest
{
    use ValidatesClaudeSecretFormat;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [ClaudeAccount::class, $this->boolean('is_system')]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSecretForValidation();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $ownerId = $this->boolean('is_system') ? null : $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('claude_accounts', 'name')->where(
                fn ($query) => $ownerId === null ? $query->whereNull('owner_user_id') : $query->where('owner_user_id', $ownerId),
            )],
            'secret' => ['required', 'string', 'max:2000', $this->secretFormatRule()],
            'default_model' => ['nullable', 'string', Rule::in(config('claude-accounts.models'))],
            'icon' => ['sometimes', Rule::enum(ClaudeAccountIcon::class)],
            'color' => ['sometimes', Rule::enum(ClaudeAccountColor::class)],
            'is_system' => ['sometimes', 'boolean'],
            'make_default' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
