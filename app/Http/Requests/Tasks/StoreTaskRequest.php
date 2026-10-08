<?php

namespace App\Http\Requests\Tasks;

use App\Models\TaskAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
