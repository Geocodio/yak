<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AcknowledgesPrivatePublish;
use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use App\Models\TaskAttachment;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Foundation\Http\FormRequest;

class SendThreadMessageRequest extends FormRequest
{
    use AcknowledgesPrivatePublish;
    use AuthorizesThreadDriving;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'model' => ['nullable', 'string', ClaudeModelCatalog::rule()],
            'intent' => ['nullable', 'string', 'in:add_preview_manifest,fix_preview,record_walkthrough,record_walkthrough_and_open_pr'],
            ThreadPublishGuard::ACKNOWLEDGEMENT_KEY => ['sometimes', 'boolean'],
            'send_key' => ['nullable', 'uuid'],
            ...TaskAttachment::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAttachment::messages();
    }
}
