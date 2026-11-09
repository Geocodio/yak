<?php

namespace App\Http\Requests\Tasks;

use App\Enums\SteeringMode;
use App\Models\TaskAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendTaskMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'option' => ['nullable', 'string'],
            'model' => ['nullable', 'string', Rule::in(config('yak.threads.models'))],
            'mode' => ['nullable', Rule::enum(SteeringMode::class)],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
