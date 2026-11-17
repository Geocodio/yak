<?php

namespace App\DataTransferObjects;

use App\Enums\StopKind;
use Carbon\CarbonInterface;

final readonly class TaskStop
{
    public function __construct(
        public StopKind $kind,
        public string $summary,
        public string $detail,
        public ?string $ask = null,
    ) {}

    public static function refreshingWorkspace(string $repositorySlug, string $detail): self
    {
        return new self(
            StopKind::YakRetrying,
            "Yak's workspace for {$repositorySlug} needed a refresh after an update, so this is paused while that runs.",
            $detail,
        );
    }

    public static function setupFailed(string $repositorySlug, string $detail): self
    {
        return new self(
            StopKind::YakStopped,
            "Yak couldn't get its workspace for {$repositorySlug} ready, so it stopped before starting.",
            $detail,
        );
    }

    public static function ciNeverReported(int $minutes, string $detail): self
    {
        return new self(
            StopKind::YakStopped,
            "The automated checks on Yak's change didn't report back within {$minutes} minutes, so Yak stopped waiting.",
            $detail,
        );
    }

    /**
     * @param  list<string>  $failingChecks
     */
    public static function ciStillFailing(int $attempts, array $failingChecks, string $detail): self
    {
        $failing = $failingChecks === [] ? '' : ' (failing: ' . implode(', ', $failingChecks) . ')';

        return new self(
            StopKind::YakStopped,
            "Yak's change still fails the automated checks after {$attempts} tries{$failing}.",
            $detail,
        );
    }

    public static function yakSideProblem(string $detail): self
    {
        return new self(
            StopKind::YakStopped,
            'Yak ran into a problem on its side and stopped before finishing.',
            $detail,
        );
    }

    public static function deployPaused(string $detail): self
    {
        return new self(
            StopKind::YakRetrying,
            'Yak was restarted for an update while working on this.',
            $detail,
        );
    }

    public static function sizeLimit(string $detail): self
    {
        return new self(
            StopKind::NeedsYou,
            "This turned out bigger than Yak's limit for a single task.",
            $detail,
            'a narrower scope, or just a reply to let Yak continue from where it stopped',
        );
    }

    /**
     * @param  list<string>  $activeRepositorySlugs
     */
    public static function repositoryUnknown(array $activeRepositorySlugs, string $detail): self
    {
        return new self(
            StopKind::NeedsYou,
            "Yak couldn't tell which repository this is for.",
            $detail,
            'the repository name (one of: ' . implode(', ', $activeRepositorySlugs) . ')',
        );
    }

    public static function unansweredQuestion(CarbonInterface $askedAt, string $detail): self
    {
        return new self(
            StopKind::NeedsYou,
            "Yak asked a question on {$askedAt->format('M j')} and didn't hear back, so it stopped.",
            $detail,
            'your answer',
        );
    }
}
