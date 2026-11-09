<?php

namespace App\Http\Requests\Settings;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClaudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'icon' => ['sometimes', Rule::enum(ClaudeAccountIcon::class)],
            'color' => ['sometimes', Rule::enum(ClaudeAccountColor::class)],
            'default_model' => ['sometimes', 'nullable', 'string', Rule::in(config('yak.threads.models', ['opus', 'sonnet', 'haiku']))],
            'is_default' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
