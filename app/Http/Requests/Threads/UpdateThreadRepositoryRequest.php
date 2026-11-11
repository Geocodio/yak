<?php

namespace App\Http\Requests\Threads;

use App\Enums\RepositoryRole;
use App\Models\Thread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateThreadRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        // A hidden thread answers exactly like a missing one.
        abort_unless($thread instanceof Thread && Gate::allows('view', $thread), 404);

        return Gate::allows('drive', $thread);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['role' => ['required', Rule::in([RepositoryRole::Changes->value, RepositoryRole::Context->value])]];
    }
}
