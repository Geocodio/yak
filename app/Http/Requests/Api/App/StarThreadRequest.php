<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

class StarThreadRequest extends FormRequest
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
        return ['starred' => ['required', 'boolean']];
    }

    public function isStarred(): bool
    {
        return $this->boolean('starred');
    }
}
