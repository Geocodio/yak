<?php

namespace App\Http\Requests\Api\App;

/**
 * The QR pairing request: the scanned code plus what the phone reports about itself.
 */
class PairDeviceRequest extends StartDeviceFlowRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:128']] + parent::rules();
    }
}
