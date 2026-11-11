<?php

namespace App\Http\Requests\Threads\Stack;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStackLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['limit' => ['required', 'integer']];
    }
}
