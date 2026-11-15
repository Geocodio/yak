<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\Stack\StoreStackChildRequest;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;

class StoreAppStackChildRequest extends StoreStackChildRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return [
            ...$rules,
            'name' => ['required', 'string', 'min:1', 'max:60'],
            'brief' => ['required', 'string', 'min:1', 'max:20000'],
            'model' => ['nullable', 'string', 'max:100', ClaudeModelCatalog::rule()],
            'send_key' => ['required', 'uuid'],
        ];
    }
}
