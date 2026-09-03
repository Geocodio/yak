<?php

namespace App\Http\Requests\Repositories;

use App\Models\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $repository = $this->route('repository');
        $repositoryId = $repository instanceof Repository ? $repository->id : null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'agent_instructions' => ['nullable', 'string', 'max:10000'],
            'git_url' => ['required', 'string', 'max:500', 'url:https'],
            'default_branch' => ['required', 'string', 'max:255'],
            'public_site_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'ci_system' => ['required', 'string', Rule::in(['github_actions', 'drone', 'none'])],
            'sentry_project' => ['nullable', 'string', 'max:255'],
            'pr_review_enabled' => ['boolean'],
            'apply_to_open_prs' => ['boolean'],
            'deployments_enabled' => ['boolean'],
            'path_excludes' => ['nullable', 'array'],
            'path_excludes.*' => ['string', 'regex:#^[A-Za-z0-9_./*?\-]+$#'],
            'selected_github_repo' => ['nullable', 'string', 'max:255'],
            'selected_github_repo_id' => ['nullable', 'integer'],
        ];

        if ($repository instanceof Repository) {
            $rules['slug'] = ['required', 'string', 'max:255', Rule::unique('repositories', 'slug')->ignore($repositoryId)];
            $rules['path'] = ['required', 'string', 'max:500', 'starts_with:/'];
        }

        return $rules;
    }
}
