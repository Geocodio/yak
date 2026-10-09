<?php

namespace App\Http\Requests\Tasks;

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
            'mode' => ['required', Rule::enum(SteeringMode::class)],
        ];
    }
}
