<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\RiskProfile;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders a repository's `.yak/` files. Every YAML file is checked with
 * the same parser Yak reads with before it is returned.
 */
class YakConfigFiles
{
    public function __construct(
        private RepositoryConfigParser $parser,
        private RepositoryRiskProfiles $riskProfiles,
    ) {}

    /**
     * @return array{files: array<string, string>, riskProfileUnapproved: bool, checksLostAppPin: list<string>, riskProfileDate: ?string}
     */
    public function forMigration(Repository $repository): array
    {
        $policy = $repository->reviewPolicy();
        $checks = array_merge($policy['required_checks'], $policy['required_statuses']);

        $config = ['version' => 1];
        if (filled($repository->description)) {
            $config['description'] = $repository->description;
        }
        $config['ci'] = (string) $repository->ci_system;
        if (filled($repository->public_site_url)) {
            $config['walkthrough'] = ['public_site_url' => $repository->public_site_url];
        }
        $config['review'] = ['enabled' => (bool) $repository->pr_review_enabled];
        if ($repository->pr_review_path_excludes !== null) {
            $config['review']['exclude_paths'] = array_values($repository->pr_review_path_excludes);
        }
        if ($repository->pr_review_policy !== null) {
            $config['review']['approval'] = [
                'mode' => $policy['mode'],
                'allowed_paths' => $policy['allowed_paths'],
                'blocked_paths' => $policy['blocked_paths'],
                'max_files' => $policy['max_files'],
                'max_lines' => $policy['max_lines'],
                'max_risk_score' => $policy['max_risk_score'],
                'min_confidence' => $policy['min_confidence'],
                'required_checks' => array_values(array_unique(array_column($checks, 'name'))),
            ];
        }

        $files = ['.yak/config.yml' => $this->render('config.yml', $config)];

        if (! empty($repository->preview_manifest)) {
            $files['.yak/preview.yml'] = $this->render('preview.yml', $repository->preview_manifest);
        }

        $instructions = trim((string) $repository->agent_instructions);
        if ($instructions !== '') {
            $this->assertValid('AGENTS.md', $instructions);
            $files['.yak/AGENTS.md'] = $instructions . "\n";
        }

        [$profile, $isUnapproved, $date] = $this->profileToMigrate($repository);
        if ($profile !== null) {
            $files['.yak/risk-profile.yml'] = $this->riskProfile($profile);
        }

        $lostAppPin = array_values(array_unique(array_column(
            array_filter($checks, fn (array $check): bool => ! empty($check['app_id']) || ! empty($check['creator_id'])),
            'name',
        )));

        return [
            'files' => $files,
            'riskProfileUnapproved' => $isUnapproved,
            'checksLostAppPin' => $lostAppPin,
            'riskProfileDate' => $date,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    public function forSetup(Repository $repository, array $manifest): array
    {
        $config = ['version' => 1];
        if (filled($repository->description)) {
            $config['description'] = $repository->description;
        }
        $config['ci'] = (string) $repository->ci_system;

        return [
            '.yak/config.yml' => $this->render('config.yml', $config),
            '.yak/preview.yml' => $this->render('preview.yml', $manifest),
        ];
    }

    /** @param array<string, mixed> $profile */
    public function riskProfile(array $profile): string
    {
        return $this->render('risk-profile.yml', [
            'version' => 1,
            'areas' => $profile['areas'],
            'unknowns' => $profile['unknowns'] ?? [],
        ]);
    }

    /** @return array{0: ?array<string, mixed>, 1: bool, 2: ?string} */
    private function profileToMigrate(Repository $repository): array
    {
        $profiles = $this->riskProfiles->forSettings($repository->slug);

        if ($profiles['active'] !== null) {
            return [$profiles['active'], false, null];
        }

        $draft = $profiles['drafts'][0] ?? null;

        if ($draft === null) {
            return [null, false, null];
        }

        $createdAt = RiskProfile::where('repo', $repository->slug)->where('version', $draft['version'])->first()?->created_at;

        return [$draft, true, $createdAt?->toDateString()];
    }

    /** @param array<string, mixed> $data */
    private function render(string $file, array $data): string
    {
        $content = '# yaml-language-server: $schema=' . RepositoryConfigSchemas::url($file) . "\n"
            . Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $this->assertValid($file, $content);

        return $content;
    }

    private function assertValid(string $file, string $content): void
    {
        $errors = $this->parser->parse($file, $content)['errors'];

        if ($errors !== []) {
            throw new \RuntimeException("Generated {$file} is invalid: " . implode('; ', $errors));
        }
    }
}
