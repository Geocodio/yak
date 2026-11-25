<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use Illuminate\Foundation\Http\FormRequest;

class UpdateThreadTitleRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:120']];
    }
}
