<?php

namespace App\Http\Requests\Threads\Stack;

use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStackLimitRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['limit' => ['required', 'integer']];
    }
}
