<?php

namespace App\Http\Requests\Threads;

use App\Enums\FileScope;
use App\Models\Thread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveThreadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        abort_unless($thread instanceof Thread && Gate::allows('view', $thread), 404);

        return Gate::allows('drive', $thread);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(FileScope::class)],
            'path' => ['required', 'string', 'max:1024'],
            'content' => ['present', 'nullable', 'string'],
            'base_sha256' => ['present', 'nullable', 'string', 'regex:/^([0-9a-f]{64})?$/i'],
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
