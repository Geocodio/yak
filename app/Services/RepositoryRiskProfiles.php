<?php

namespace App\Services;

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\YakTask;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RepositoryRiskProfiles
{
    public function generate(Repository $repository, string $source = 'cli'): YakTask
    {
        $task = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'risk-profile-' . Str::uuid(),
            'mode' => TaskMode::Research, 'source' => $source,
            'description' => app(PromptResolver::class)->render('tasks-risk-profile'),
            'context' => json_encode(['risk_profile_draft' => true]),
        ]);
        app(AgentJobDispatcher::class)->dispatch($task, ResearchYakJob::class);

        return $task;
    }

    /** @return array{active: array<string, mixed>|null, drafts: array<int, array<string, mixed>>} */
    public function forSettings(string $repo): array
    {
        $rows = RiskProfile::where('repo', $repo)->orderByDesc('created_at')->limit(20)->get();
        $drafts = [];
        foreach ($rows as $row) {
            $profile = $row->profile;
            if (($profile['repo'] ?? null) === $repo && ($profile['version'] ?? '') === $this->version($profile)) {
                $drafts[] = $profile;
            }
        }

        return ['active' => $this->active($repo), 'drafts' => $drafts];
    }

    /** @return array<string, mixed> */
    public function draft(string $repo, string $sha, string $output): array
    {
        if (strlen($output) > 1048576) {
            throw new \RuntimeException('Risk profile exceeds 1 MiB.');
        }
        $json = trim($output);
        if (preg_match('/```json\s*(.*?)\s*```/s', $json, $match) === 1) {
            $json = $match[1];
        }
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \RuntimeException('Risk profile must be a JSON object.');
        }
        $validated = Validator::make($data, [
            'areas' => ['required', 'array', 'min:1', 'max:100'],
            'areas.*' => ['required', 'array:name,paths,symbols,risk,rationale,evidence'],
            'areas.*.name' => ['required', 'string', 'max:200'],
            'areas.*.paths' => ['required', 'array', 'min:1', 'max:50'],
            'areas.*.paths.*' => ['required', 'string', 'max:500'],
            'areas.*.symbols' => ['present', 'array', 'max:100'],
            'areas.*.symbols.*' => ['string', 'max:500'],
            'areas.*.risk' => ['required', 'in:low,medium,high,critical,unknown'],
            'areas.*.rationale' => ['required', 'string', 'max:4000'],
            'areas.*.evidence' => ['required', 'array', 'min:1', 'max:100'],
            'areas.*.evidence.*' => ['required', 'string', 'max:1000'],
            'unknowns' => ['present', 'array', 'max:100'],
            'unknowns.*' => ['string', 'max:2000'],
        ])->validate();
        if (preg_match('/^[a-f0-9]{40,64}$/', $sha) !== 1) {
            throw new \RuntimeException('Risk profile source SHA is invalid.');
        }
        $profile = ['schema_version' => 1, 'repo' => $repo, 'source_sha' => $sha,
            'areas' => $validated['areas'], 'unknowns' => $validated['unknowns']];
        $profile['version'] = $this->version($profile);
        RiskProfile::updateOrCreate(
            ['repo' => $repo, 'version' => $profile['version']],
            ['profile' => $profile],
        );

        return $profile;
    }

    /** @param array<string, mixed> $profile */
    private function version(array $profile): string
    {
        return hash('sha256', json_encode([
            $profile['schema_version'], $profile['repo'], $profile['source_sha'],
            $profile['areas'], $profile['unknowns'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function approve(string $repo, string $version, string $reviewer): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $version) !== 1 || trim($reviewer) === '') {
            throw new \RuntimeException('An exact draft hash and human reviewer are required.');
        }
        $row = RiskProfile::where('repo', $repo)->where('version', $version)->first();
        if ($row === null) {
            throw new \RuntimeException('Draft hash or repository mismatch.');
        }
        $profile = $row->profile;
        if (($profile['repo'] ?? null) !== $repo || $this->version($profile) !== $version) {
            throw new \RuntimeException('Draft hash or repository mismatch.');
        }
        $row->approved_by = trim($reviewer);
        $row->approved_at = now();
        $row->save();

        return $profile + ['approved_by' => $row->approved_by, 'approved_at' => $row->approved_at->toIso8601String()];
    }

    /** @return array<string, mixed>|null */
    public function active(string $repo): ?array
    {
        try {
            $row = RiskProfile::where('repo', $repo)->whereNotNull('approved_at')
                ->orderByDesc('approved_at')->orderByDesc('id')->first();
            if ($row === null) {
                return null;
            }
            $profile = $row->profile;
            $profile['approved_by'] = $row->approved_by;
            $profile['approved_at'] = $row->approved_at?->toIso8601String();
            if (($profile['repo'] ?? null) !== $repo || empty($profile['approved_by'])
                || empty($profile['approved_at']) || ($profile['version'] ?? '') !== $this->version($profile)
                || now()->parse($profile['approved_at'])->lt(now()->subDays((int) (Repository::where('slug', $repo)->first()?->reviewPolicy()['profile_max_age_days'] ?? 90)))) {
                return null;
            }

            return $profile;
        } catch (\Throwable) {
            return null;
        }
    }
}
