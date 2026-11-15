<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Tasks\RerouteTaskRequest;

/**
 * The repository is checked by the action itself, so an inactive or unknown one gives the same 422 on `repo`.
 */
class RerouteAppTaskRequest extends RerouteTaskRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'repo' => ['required', 'string', 'max:255'],
        ];
    }
}
