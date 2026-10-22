<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

class TagCoOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
