<?php

namespace App\Http\Requests\Api\App;

use App\Enums\MessageDelivery;
use App\Http\Requests\Threads\SendThreadMessageRequest;
use Illuminate\Validation\Rule;

class SendAppThreadMessageRequest extends SendThreadMessageRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'attachment_refs' => ['nullable', 'array', 'max:' . (int) config('yak.attachments.max_files')],
            'send_key' => ['required', 'uuid'],
            'delivery' => ['nullable', Rule::enum(MessageDelivery::class)],
        ];
    }

    public function delivery(): MessageDelivery
    {
        return MessageDelivery::tryFrom((string) $this->validated('delivery')) ?? MessageDelivery::Steer;
    }
}
