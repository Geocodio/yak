<?php

namespace App\Http\Requests\Api\App;

use App\Enums\FileScope;
use App\Models\Thread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ListThreadFilesRequest extends FormRequest
{
    /** Listing the repository is for everyone who can see the thread; the whole sandbox is for people who can drive it. */
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        if ($this->query('scope') !== FileScope::Sandbox->value || ! $thread instanceof Thread) {
            return true;
        }

        return Gate::allows('drive', $thread);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(FileScope::class)],
            'path' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ];
    }

    public function fileScope(): FileScope
    {
        return FileScope::from($this->validated('scope'));
    }

    public function folderPath(): string
    {
        return (string) ($this->validated('path') ?? '');
    }
}
