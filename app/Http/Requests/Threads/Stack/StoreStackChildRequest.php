<?php

namespace App\Http\Requests\Threads\Stack;

use App\Enums\ThreadKind;
use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStackChildRequest extends FormRequest
{
    use AuthorizesThreadDriving;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'brief' => ['required', 'string', 'max:20000'],
            'kind' => ['required', Rule::in([ThreadKind::Research->value, ThreadKind::Implement->value, ThreadKind::Mockup->value, ThreadKind::General->value])],
            'model' => ['nullable', 'string', ClaudeModelCatalog::rule()],
        ];
    }
}
