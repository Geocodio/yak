<?php

namespace App\Services\PreviewRepair;

use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use Illuminate\Support\Str;

/**
 * Identifies the cause of a preview failure, so the same cause is recognised
 * across previews of one repository.
 *
 * The phase and text come from the command that failed: the error row that
 * the failure wrote last, and the tail of its output. A failure outside a
 * command (cloning, starting, the health probe) falls back to the failure
 * reason. The job prefix (`deploy:`, `wake:`, `rebuild:`) is dropped, so a
 * failed deploy and a failed rebuild with the same error are one cause.
 */
final readonly class PreviewFailureFingerprint
{
    private const OUTPUT_LINES = 20;

    private const EXCERPT_LENGTH = 4000;

    public function __construct(
        public string $phase,
        public string $excerpt,
        public string $hash,
    ) {}

    public static function for(BranchDeployment $deployment, ?string $exceptionMessage = null): self
    {
        $failedCommand = self::failedCommand($deployment);
        $reason = (string) preg_replace('/^(deploy|wake|rebuild): /', '', (string) ($exceptionMessage ?? $deployment->failure_reason));

        if ($failedCommand !== null) {
            $phase = $failedCommand->phase;
            $output = trim($failedCommand->output());
            $text = $output === '' ? $reason : implode("\n", array_slice(explode("\n", $output), -self::OUTPUT_LINES));
        } else {
            $phase = self::phaseFromMessage($reason);
            $text = $reason;
        }

        $excerpt = Str::limit(trim($text), self::EXCERPT_LENGTH, '');

        return new self(
            phase: $phase,
            excerpt: $excerpt,
            hash: hash('sha256', $deployment->repository_id . '|' . $phase . '|' . self::normalize($excerpt)),
        );
    }

    /**
     * Strips what differs between two occurrences of one cause: container
     * names, preview hostnames, commit SHAs, timestamps, durations, build
     * step numbers and any other digits.
     */
    public static function normalize(string $text): string
    {
        $suffix = preg_quote((string) config('yak.deployments.hostname_suffix'), '/');

        $replacements = [
            '/\bdeploy-[0-9a-z]{26}\b/i' => 'container',
            '/\btask-[0-9a-z-]+\b/i' => 'container',
            "/\\b[0-9a-z.-]+\\.{$suffix}\\b/i" => 'hostname',
            '/\b(?=[0-9a-f]*\d)[0-9a-f]{7,40}\b/i' => 'sha',
            '/\b\d{4}-\d{2}-\d{2}[t ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(z|[+-]\d{2}:?\d{2})?\b/i' => 'time',
            '/\b\d{1,2}:\d{2}:\d{2}\b/' => 'time',
            '/\b\d+(\.\d+)?(ms|s|m)\b/' => 'duration',
            '/#\d+/' => '#',
            '/\d+/' => 'n',
            '/\s+/' => ' ',
        ];

        return mb_strtolower(trim((string) preg_replace(array_keys($replacements), array_values($replacements), $text)));
    }

    /**
     * The command whose failure this is: the newest log row when it is a
     * failed command, or the row just before the job's own failure line.
     * An older failed command, from a run that later moved on, never counts.
     */
    private static function failedCommand(BranchDeployment $deployment): ?DeploymentLog
    {
        $latest = DeploymentLog::query()
            ->where('branch_deployment_id', $deployment->id)
            ->latest('id')
            ->limit(2)
            ->get();

        $candidate = $latest->first();

        if ($candidate !== null && $candidate->phase === 'lifecycle' && $candidate->level === 'error') {
            $candidate = $latest->get(1);
        }

        if ($candidate === null || $candidate->level !== 'error' || $candidate->phase === 'lifecycle') {
            return null;
        }

        return $candidate;
    }

    private static function phaseFromMessage(string $message): string
    {
        if (preg_match('/\(phase=([a-z_]+)\)|during phase: ([a-z_]+)/', $message, $matches) === 1) {
            return $matches[1] !== '' ? $matches[1] : $matches[2];
        }

        return 'lifecycle';
    }
}
