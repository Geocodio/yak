<?php

namespace App\Http\Requests\Api\App;

use App\Enums\SteeringMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQueuedMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'required_without:text', Rule::enum(SteeringMode::class)],
            'text' => ['nullable', 'required_without:mode', 'string', 'min:1', 'max:20000'],
        ];
    }
}
