<?php

namespace App\Http\Requests\Repositories;

use App\Models\ClaudeAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRepositoryClaudeAccountRequest extends FormRequest
{
    private function isUnattended(): bool
    {
        return $this->routeIs('repos.unattended-claude-account.update');
    }

    public function authorize(): bool
    {
        return ! $this->isUnattended() || ($this->user()?->can('useAsUnattended', ClaudeAccount::class) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'claude_account_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $account = ClaudeAccount::query()->checked()->find((int) $value);

                $allowed = $account !== null && $account->isUsable() && match (true) {
                    $this->isUnattended() => $account->isSystem(),
                    default => $account->isSystem() || $account->owner_user_id === $this->user()?->id,
                };

                if (! $allowed) {
                    $fail('Pick one of your own accounts or a Yak account that is working.');
                }
            }],
            'model' => ['nullable', 'string', Rule::in(config('claude-accounts.models'))],
        ];
    }
}
