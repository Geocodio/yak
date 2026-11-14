<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Cli\PollDeviceTokenRequest as CliPollDeviceTokenRequest;

/**
 * The phone's poll: the device code plus an optional request id that lets a lost response be retried once.
 */
class PollDeviceTokenRequest extends CliPollDeviceTokenRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + ['request_id' => ['nullable', 'uuid']];
    }
}
