<?php

namespace App\Http\Requests\Threads;

use App\Enums\ThreadShareMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateThreadShareRequest extends FormRequest
{
    /**
     * Ownership is decided in the controller, after the visibility check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unknown or deleted ids are dropped by ThreadSharing, not rejected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(ThreadShareMode::class)],
            'user_ids' => ['required_if:mode,' . ThreadShareMode::People->value, 'array', 'max:200'],
            'user_ids.*' => ['integer'],
        ];
    }

    public function mode(): ThreadShareMode
    {
        return ThreadShareMode::from($this->string('mode')->toString());
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('user_ids', [])));
    }
}
