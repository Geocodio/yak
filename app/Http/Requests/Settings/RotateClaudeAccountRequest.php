<?php

namespace App\Http\Requests\Settings;

use App\Http\Concerns\ValidatesClaudeSecretFormat;
use App\Models\ClaudeAccount;
use Illuminate\Foundation\Http\FormRequest;

class RotateClaudeAccountRequest extends FormRequest
{
    use ValidatesClaudeSecretFormat;

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof ClaudeAccount && ($this->user()?->can('manage', $account) ?? false);
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
        /** @var ClaudeAccount $account */
        $account = $this->route('account');

        return [
            'secret' => ['required', 'string', 'max:2000', $this->secretFormatRule($account->kind)],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
