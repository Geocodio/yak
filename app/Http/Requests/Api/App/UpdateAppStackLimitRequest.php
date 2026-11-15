<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\Stack\UpdateStackLimitRequest;

class UpdateAppStackLimitRequest extends UpdateStackLimitRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['limit' => ['required', 'integer', 'between:1,' . (int) config('yak.threads.person_awake_cap')]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['limit.between' => 'Choose between 1 and ' . (int) config('yak.threads.person_awake_cap') . '.'];
    }
}
