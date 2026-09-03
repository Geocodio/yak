<?php

namespace App\Http\Requests\Skills;

use Illuminate\Foundation\Http\FormRequest;

class AddMarketplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'min:3'],
        ];
    }
}
