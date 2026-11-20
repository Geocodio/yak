<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use Illuminate\Foundation\Http\FormRequest;

class SendAgentMessageRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:5000']];
    }
}
