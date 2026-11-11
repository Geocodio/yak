<?php

namespace App\Http\Requests\Threads;

use App\Http\Requests\Concerns\AcknowledgesPrivatePublish;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Foundation\Http\FormRequest;

class OpenThreadPullRequestRequest extends FormRequest
{
    use AcknowledgesPrivatePublish;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [ThreadPublishGuard::ACKNOWLEDGEMENT_KEY => ['sometimes', 'boolean']];
    }
}
