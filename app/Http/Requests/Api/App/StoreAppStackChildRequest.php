<?php

namespace App\Http\Requests\Api\App;

use App\Enums\EffortLevel;
use App\Http\Requests\Threads\Stack\StoreStackChildRequest;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Validation\Rule;

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
            'effort' => ['nullable', Rule::enum(EffortLevel::class)],
            'send_key' => ['required', 'uuid'],
        ];
    }
}
