<?php

namespace App\DataTransferObjects;

use App\Enums\StopKind;
use Carbon\CarbonInterface;

final readonly class TaskStop
{
    private const string CI_NEVER_REPORTED = "The automated checks on Yak's change didn't report back";

    private const string CI_STILL_FAILING = "Yak's change still fails the automated checks";

    /** The error a CI retry ends on when it changed nothing and GitHub would not run the failed checks again. */
    private const string CI_RERUN_REFUSED = 'Retry made no changes to the branch, so CI could not run again on it.';

    public function __construct(
        public StopKind $kind,
        public string $summary,
        public string $detail,
        public ?string $ask = null,
        public bool $canContinueAsIs = false,
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
            self::CI_NEVER_REPORTED . " within {$minutes} minutes, so Yak stopped waiting.",
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
            self::CI_STILL_FAILING . " after {$attempts} tries{$failing}.",
            $detail,
        );
    }

    /**
     * Whether a recorded stop came from CI: checks that kept failing, never reported, or could not run again.
     */
    public static function isCiStop(?string $summary, ?string $detail): bool
    {
        return str_starts_with((string) $summary, self::CI_STILL_FAILING)
            || str_starts_with((string) $summary, self::CI_NEVER_REPORTED)
            || str_starts_with((string) $detail, self::CI_RERUN_REFUSED);
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
            'a narrower scope',
            true,
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
            'the repository name' . ($activeRepositorySlugs === [] ? '' : ' (one of: ' . implode(', ', $activeRepositorySlugs) . ')'),
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
