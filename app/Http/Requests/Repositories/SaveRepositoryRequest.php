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

    protected function prepareForValidation(): void
    {
        $policy = $this->input('pr_review_policy');
        if (! is_array($policy)) {
            return;
        }
        foreach (['allowed_paths', 'blocked_paths'] as $key) {
            if (isset($policy[$key]) && is_array($policy[$key])) {
                $policy[$key] = array_values(array_filter(array_map(
                    fn (mixed $path): mixed => is_string($path) ? trim($path) : $path,
                    $policy[$key],
                ), fn (mixed $path): bool => $path !== '' && $path !== null));
            }
        }
        $this->merge(['pr_review_policy' => $policy]);
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
            'pr_review_policy' => ['sometimes', 'array:mode,allowed_paths,blocked_paths,required_checks,required_statuses,max_files,max_lines,max_risk_score,min_confidence,profile_max_age_days'],
            'pr_review_policy.mode' => ['required_with:pr_review_policy', Rule::in(['off', 'shadow', 'enforce'])],
            'pr_review_policy.allowed_paths' => ['present_with:pr_review_policy', 'array', 'max:100'],
            'pr_review_policy.allowed_paths.*' => ['required', 'string', 'max:500', 'regex:#^[A-Za-z0-9_./*?\-]+$#'],
            'pr_review_policy.blocked_paths' => ['present_with:pr_review_policy', 'array', 'max:100'],
            'pr_review_policy.blocked_paths.*' => ['required', 'string', 'max:500', 'regex:#^[A-Za-z0-9_./*?\-]+$#'],
            'pr_review_policy.required_checks' => ['present_with:pr_review_policy', 'array', 'max:50'],
            'pr_review_policy.required_checks.*' => ['array:name,app_id'],
            'pr_review_policy.required_checks.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'pr_review_policy.required_checks.*.app_id' => ['required', 'integer', 'min:1'],
            'pr_review_policy.required_statuses' => ['present_with:pr_review_policy', 'array', 'max:50'],
            'pr_review_policy.required_statuses.*' => ['array:name,creator_id'],
            'pr_review_policy.required_statuses.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'pr_review_policy.required_statuses.*.creator_id' => ['required', 'integer', 'min:1'],
            'pr_review_policy.max_files' => ['required_with:pr_review_policy', 'integer', 'between:1,100'],
            'pr_review_policy.max_lines' => ['required_with:pr_review_policy', 'integer', 'between:1,5000'],
            'pr_review_policy.max_risk_score' => ['required_with:pr_review_policy', 'integer', 'between:0,30'],
            'pr_review_policy.min_confidence' => ['required_with:pr_review_policy', 'integer', 'between:80,100'],
            'pr_review_policy.profile_max_age_days' => ['required_with:pr_review_policy', 'integer', 'between:1,90'],
            'apply_to_open_prs' => ['boolean'],
            'deployments_enabled' => ['boolean'],
            'path_excludes' => ['nullable', 'array'],
            'path_excludes.*' => ['string', 'regex:#^[A-Za-z0-9_./*?\-]+$#'],
            'selected_github_repo' => ['nullable', 'string', 'max:255'],
            'selected_github_repo_id' => ['nullable', 'integer'],

            // Preview manifest, submitted alongside the repository fields
            // from the same "Save repository" button. Present only when the
            // edit page rendered the branch-deployments section.
            'manifest' => ['sometimes', 'array'],
            'manifest.port' => ['required_with:manifest', 'integer', 'min:1', 'max:65535'],
            'manifest.health_probe_path' => ['required_with:manifest', 'string', 'starts_with:/'],
            'manifest.cold_start' => ['nullable', 'string'],
            'manifest.checkout_refresh' => ['nullable', 'string'],
            'manifest.wake_timeout_seconds' => ['integer', 'min:1'],
        ];

        if ($repository instanceof Repository) {
            $rules['slug'] = ['required', 'string', 'max:255', Rule::unique('repositories', 'slug')->ignore($repositoryId)];
            $rules['path'] = ['required', 'string', 'max:500', 'starts_with:/'];
        }

        return $rules;
    }
}
