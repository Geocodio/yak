<?php

namespace App\DataTransferObjects;

use App\Models\Repository;

/**
 * Effective repository settings: a value from `.yak/` wins, and a missing
 * file or key falls back to the database column.
 */
final readonly class RepositorySettings
{
    public function __construct(public Repository $repository, public ConfigSnapshot $snapshot) {}

    public function description(): ?string
    {
        return $this->config()['description'] ?? $this->repository->description;
    }

    public function ciSystem(): string
    {
        return $this->config()['ci'] ?? (string) $this->repository->ci_system;
    }

    public function publicSiteUrl(): ?string
    {
        return $this->config()['walkthrough']['public_site_url'] ?? $this->repository->public_site_url;
    }

    public function largeChangeLines(): int
    {
        return (int) ($this->config()['pull_requests']['large_change_lines'] ?? config('yak.large_change_threshold'));
    }

    public function reviewEnabled(): bool
    {
        return (bool) ($this->config()['review']['enabled'] ?? $this->repository->pr_review_enabled);
    }

    /** @return array<int, string>|null */
    public function reviewPathExcludes(): ?array
    {
        return $this->config()['review']['exclude_paths'] ?? $this->repository->pr_review_path_excludes;
    }

    /**
     * The database policy. The file's `review.approval` block still parses but
     * is ignored; the approval level lives in the dashboard.
     *
     * @return array<string, mixed>
     */
    public function reviewPolicy(): array
    {
        return $this->repository->reviewPolicy();
    }

    public function agentInstructions(): string
    {
        $instructions = $this->snapshot->data('AGENTS.md');

        return trim(is_string($instructions) ? $instructions : (string) ($this->repository->agent_instructions ?? ''));
    }

    /** @return array<string, mixed>|null */
    public function previewManifest(): ?array
    {
        $manifest = $this->snapshot->data('preview.yml');

        return is_array($manifest) ? $manifest : $this->repository->preview_manifest;
    }

    public function hasPreviewScript(): bool
    {
        return $this->snapshot->data('preview.sh') !== null;
    }

    /**
     * The profile from `.yak/risk-profile.yml` in the shape the reviewer uses.
     * Merging the file's PR approved it, so it never expires. Null means the
     * caller falls back to the database profile.
     *
     * @return array<string, mixed>|null
     */
    public function riskProfile(): ?array
    {
        $file = $this->snapshot->file('risk-profile.yml');

        if ($file === null || ! is_array($file->data) || ($file->data['version'] ?? 1) !== 1) {
            return null;
        }

        return [
            'schema_version' => 1,
            'repo' => $this->repository->slug,
            'source' => 'file',
            'source_sha' => $file->validCommitSha,
            'areas' => $file->data['areas'],
            'unknowns' => $file->data['unknowns'] ?? [],
            'version' => hash('sha256', (string) $file->content),
        ];
    }

    /**
     * The plain-language concerns from a version 2 `.yak/risk-profile.yml`.
     * Null when the file is missing, invalid or version 1.
     *
     * @return list<array{name: string, approval: 'always_human'|'when_tested', description: string}>|null
     */
    public function concerns(): ?array
    {
        $file = $this->snapshot->file('risk-profile.yml');

        if ($file === null || ! is_array($file->data) || ($file->data['version'] ?? null) !== 2) {
            return null;
        }

        return $file->data['concerns'];
    }

    /** The sha256 of the concerns file content, null when `concerns()` is null. */
    public function concernsVersion(): ?string
    {
        return $this->concerns() === null
            ? null
            : hash('sha256', (string) $this->snapshot->file('risk-profile.yml')?->content);
    }

    /**
     * Once any valid `config.yml` said `enforce`, the gate stays on while the
     * file is broken or GitHub cannot be read. A valid file that says `off`
     * turns it off.
     */
    public function coOwnerGateMode(): string
    {
        $file = $this->snapshot->file('config.yml');
        $isBroken = $this->snapshot->state === 'unreachable' || ($file !== null && ! $file->isValid());

        if ($isBroken && $this->repository->co_owner_gate_enforced_at !== null) {
            return 'enforce';
        }

        return $this->config()['co_owner_gate']['mode'] ?? 'off';
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->snapshot->data('config.yml');

        return is_array($config) ? $config : [];
    }
}
