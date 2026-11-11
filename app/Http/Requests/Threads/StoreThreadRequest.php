<?php

namespace App\Http\Requests\Threads;

use App\Models\TaskAttachment;
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
            'description' => ['required', 'string', 'min:3', 'max:10000'],
            'claude_account_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', Rule::in((array) config('claude-accounts.models'))],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
