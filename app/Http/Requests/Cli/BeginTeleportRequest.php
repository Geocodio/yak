<?php

namespace App\Http\Requests\Cli;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BeginTeleportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['up', 'down'])],
            'session_id' => ['required_if:direction,up', 'nullable', 'uuid'],
            'title_hint' => ['nullable', 'string', 'max:200'],
            'git_name' => ['nullable', 'string', 'max:200'],
            'git_email' => ['nullable', 'string', 'max:200'],
            'local_path' => ['nullable', 'string', 'max:500'],
            'os' => ['nullable', 'string', 'max:32'],
            'arch' => ['nullable', 'string', 'max:32'],
            'repos' => ['required_if:direction,up', 'array', 'min:1', 'max:10'],
            'repos.0.role' => ['in:primary'],
            'repos.*.repo' => ['required', 'string', 'max:200'],
            'repos.*.role' => ['required', Rule::in(['primary', 'changes', 'context'])],
            'repos.*.local_path' => ['nullable', 'string', 'max:500'],
            'repos.*.branch' => ['nullable', 'string', 'max:255'],
            'repos.*.on_default_branch' => ['nullable', 'boolean'],
            'repos.*.head_sha' => ['nullable', 'string', 'max:64'],
            'missing_directories' => ['nullable', 'array'],
            'missing_directories.*' => ['string', 'max:500'],
        ];
    }
}
