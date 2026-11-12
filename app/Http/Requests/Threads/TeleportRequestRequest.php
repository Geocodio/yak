<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AcknowledgesPrivatePublish;
use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Foundation\Http\FormRequest;

class TeleportRequestRequest extends FormRequest
{
    use AcknowledgesPrivatePublish;
    use AuthorizesThreadDriving;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'running_turn' => ['nullable', 'in:wait,stop'],
            ThreadPublishGuard::ACKNOWLEDGEMENT_KEY => ['sometimes', 'boolean'],
        ];
    }
}
