<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates one file from a repository's `.yak/` directory.
 *
 * Limits match the dashboard form requests, so a value the form rejects is
 * also rejected in a file.
 */
class RepositoryConfigParser
{
    /** File names under `.yak/` that Yak reads. */
    public const FILES = ['config.yml', 'preview.yml', 'risk-profile.yml', 'AGENTS.md', 'preview.sh'];

    private const MAX_BYTES = 1048576;

    private const PATH_GLOB = '#^[A-Za-z0-9_./*?\-]+$#';

    /** @var array<string, list<string>> */
    private const KEYS = [
        'config.yml' => ['version', 'description', 'ci', 'walkthrough', 'pull_requests', 'co_owner_gate', 'review'],
        'preview.yml' => ['port', 'health_probe_path', 'cold_start', 'checkout_refresh', 'wake_timeout_seconds',
            'cold_start_timeout_seconds', 'checkout_refresh_timeout_seconds', 'health_probe_timeout_seconds'],
        'risk-profile.yml' => ['version', 'areas', 'unknowns'],
    ];

    /** @return array{data: mixed, errors: list<string>} */
    public function parse(string $file, string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            return $this->invalid(["{$file}: must be at most 1 MiB"]);
        }

        if ($file === 'AGENTS.md') {
            return mb_strlen($content) > 10000
                ? $this->invalid(['AGENTS.md: must be at most 10000 characters'])
                : ['data' => $content, 'errors' => []];
        }

        if ($file === 'preview.sh') {
            return ['data' => $content, 'errors' => []];
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException $exception) {
            return $this->invalid(["{$file}: {$exception->getMessage()}"]);
        }

        if (! is_array($data) || $data === [] || array_is_list($data)) {
            return $this->invalid(["{$file}: must be a YAML mapping"]);
        }

        $errors = array_map(
            fn (string|int $key): string => "{$key}: unknown key",
            array_values(array_diff(array_keys($data), self::KEYS[$file])),
        );

        $validator = Validator::make($data, $this->rules($file));
        foreach ($validator->errors()->messages() as $key => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$key}: {$message}";
            }
        }

        return $errors === [] ? ['data' => $data, 'errors' => []] : $this->invalid($errors);
    }

    /**
     * @param  list<string>  $errors
     * @return array{data: null, errors: list<string>}
     */
    private function invalid(array $errors): array
    {
        return ['data' => null, 'errors' => $errors];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(string $file): array
    {
        return match ($file) {
            'config.yml' => [
                'version' => ['required', 'integer', 'in:1'],
                'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'ci' => ['sometimes', Rule::in(['github_actions', 'drone', 'none'])],
                'walkthrough' => ['sometimes', 'array:public_site_url'],
                'walkthrough.public_site_url' => ['sometimes', 'nullable', 'url', 'max:255'],
                'pull_requests' => ['sometimes', 'array:large_change_lines'],
                'pull_requests.large_change_lines' => ['sometimes', 'integer', 'min:1'],
                'co_owner_gate' => ['sometimes', 'array:mode'],
                'co_owner_gate.mode' => ['sometimes', Rule::in(['off', 'enforce'])],
                'review' => ['sometimes', 'array:enabled,exclude_paths,approval'],
                'review.enabled' => ['sometimes', 'boolean'],
                'review.exclude_paths' => ['sometimes', 'array', 'max:100'],
                'review.exclude_paths.*' => ['required', 'string', 'max:500', 'regex:' . self::PATH_GLOB],
                'review.approval' => ['sometimes', 'array:mode,allowed_paths,blocked_paths,required_checks,max_files,max_lines,max_risk_score,min_confidence'],
                'review.approval.mode' => ['required_with:review.approval', Rule::in(['off', 'shadow', 'enforce'])],
                'review.approval.allowed_paths' => ['sometimes', 'array', 'max:100'],
                'review.approval.allowed_paths.*' => ['required', 'string', 'max:500', 'regex:' . self::PATH_GLOB],
                'review.approval.blocked_paths' => ['sometimes', 'array', 'max:100'],
                'review.approval.blocked_paths.*' => ['required', 'string', 'max:500', 'regex:' . self::PATH_GLOB],
                'review.approval.required_checks' => ['sometimes', 'array', 'max:50'],
                'review.approval.required_checks.*' => ['required', 'string', 'max:255', 'distinct'],
                'review.approval.max_files' => ['sometimes', 'integer', 'between:1,100'],
                'review.approval.max_lines' => ['sometimes', 'integer', 'between:1,5000'],
                'review.approval.max_risk_score' => ['sometimes', 'integer', 'between:0,30'],
                'review.approval.min_confidence' => ['sometimes', 'integer', 'between:80,100'],
            ],
            'preview.yml' => [
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'health_probe_path' => ['required', 'string', 'starts_with:/'],
                'cold_start' => ['sometimes', 'nullable', 'string'],
                'checkout_refresh' => ['sometimes', 'nullable', 'string'],
                'wake_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
                'cold_start_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
                'checkout_refresh_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
                'health_probe_timeout_seconds' => ['sometimes', 'integer', 'min:1'],
            ],
            'risk-profile.yml' => [
                'version' => ['required', 'integer', 'in:1'],
                ...RepositoryRiskProfiles::areaRules(),
                'areas.*.name' => ['required', 'string', 'max:200', 'distinct'],
                'unknowns' => ['sometimes', 'array', 'max:100'],
                'unknowns.*' => ['string', 'max:2000'],
            ],
            default => throw new \InvalidArgumentException("Unknown file {$file}"),
        };
    }
}
