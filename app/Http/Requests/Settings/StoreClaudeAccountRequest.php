<?php

namespace App\Http\Requests\Settings;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Enums\ClaudeAccountKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaudeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(ClaudeAccountKind::class)],
            'secret' => ['required', 'string', 'max:2000'],
            'icon' => ['sometimes', Rule::enum(ClaudeAccountIcon::class)],
            'color' => ['sometimes', Rule::enum(ClaudeAccountColor::class)],
            'default_model' => ['nullable', 'string', Rule::in(config('yak.threads.models', ['opus', 'sonnet', 'haiku']))],
            'is_system' => ['sometimes', 'boolean'],
        ];
    }
}
