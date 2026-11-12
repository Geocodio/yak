<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use App\Models\TaskAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendThreadMessageRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'model' => ['nullable', 'string', Rule::in((array) config('claude-accounts.models'))],
            'send_key' => ['nullable', 'uuid'],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
