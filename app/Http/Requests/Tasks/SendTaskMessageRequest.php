<?php

namespace App\Http\Requests\Tasks;

use App\Models\TaskAttachment;
use Illuminate\Foundation\Http\FormRequest;

class SendTaskMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'option' => ['nullable', 'string'],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
