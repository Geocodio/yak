<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use Illuminate\Foundation\Http\FormRequest;

class ReorderMergeOrderRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ];
    }
}
