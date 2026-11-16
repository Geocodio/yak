<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\StoreThreadRequest;

class StoreAppThreadRequest extends StoreThreadRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'send_key' => ['required', 'uuid'],
        ];
    }
}
