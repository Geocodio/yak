<?php

namespace App\Http\Requests\Threads;

use Illuminate\Foundation\Http\FormRequest;

class TeleportRequestRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['running_turn' => ['nullable', 'in:wait,stop']];
    }
}
