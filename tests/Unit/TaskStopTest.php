<?php

use App\DataTransferObjects\TaskStop;
use App\Enums\StopKind;
use Illuminate\Support\Carbon;

it('describes a workspace refresh', function () {
    $stop = TaskStop::refreshingWorkspace('acme/app', 'd');

    expect($stop->kind)->toBe(StopKind::YakRetrying)
        ->and($stop->summary)->toBe("Yak's workspace for acme/app needed a refresh after an update, so this is paused while that runs.")
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');
});

it('describes a failed setup', function () {
    $stop = TaskStop::setupFailed('acme/app', 'd');

    expect($stop->kind)->toBe(StopKind::YakStopped)
        ->and($stop->summary)->toBe("Yak couldn't get its workspace for acme/app ready, so it stopped before starting.")
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');
});

it('describes checks that never reported', function () {
    $stop = TaskStop::ciNeverReported(45, 'd');

    expect($stop->kind)->toBe(StopKind::YakStopped)
        ->and($stop->summary)->toBe("The automated checks on Yak's change didn't report back within 45 minutes, so Yak stopped waiting.")
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');
});

it('describes checks that keep failing', function () {
    $stop = TaskStop::ciStillFailing(3, ['lint', 'tests'], 'd');

    expect($stop->kind)->toBe(StopKind::YakStopped)
        ->and($stop->summary)->toBe("Yak's change still fails the automated checks after 3 tries (failing: lint, tests).")
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');

    expect(TaskStop::ciStillFailing(2, [], 'x')->summary)
        ->toBe("Yak's change still fails the automated checks after 2 tries.");
});

it('describes a problem on the Yak side', function () {
    $stop = TaskStop::yakSideProblem('d');

    expect($stop->kind)->toBe(StopKind::YakStopped)
        ->and($stop->summary)->toBe('Yak ran into a problem on its side and stopped before finishing.')
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');
});

it('describes a deploy pause', function () {
    $stop = TaskStop::deployPaused('d');

    expect($stop->kind)->toBe(StopKind::YakRetrying)
        ->and($stop->summary)->toBe('Yak was restarted for an update while working on this.')
        ->and($stop->ask)->toBeNull()
        ->and($stop->detail)->toBe('d');
});

it('describes the size limit', function () {
    $stop = TaskStop::sizeLimit('d');

    expect($stop->kind)->toBe(StopKind::NeedsYou)
        ->and($stop->summary)->toBe("This turned out bigger than Yak's limit for a single task.")
        ->and($stop->ask)->toBe('a narrower scope, or just a reply to let Yak continue from where it stopped')
        ->and($stop->detail)->toBe('d');
});

it('describes an unknown repository', function () {
    $stop = TaskStop::repositoryUnknown(['a/b', 'c/d'], 'd');

    expect($stop->kind)->toBe(StopKind::NeedsYou)
        ->and($stop->summary)->toBe("Yak couldn't tell which repository this is for.")
        ->and($stop->ask)->toBe('the repository name (one of: a/b, c/d)')
        ->and($stop->detail)->toBe('d');
});

it('describes an unanswered question', function () {
    $stop = TaskStop::unansweredQuestion(Carbon::parse('2026-11-03'), 'd');

    expect($stop->kind)->toBe(StopKind::NeedsYou)
        ->and($stop->summary)->toBe("Yak asked a question on Nov 3 and didn't hear back, so it stopped.")
        ->and($stop->ask)->toBe('your answer')
        ->and($stop->detail)->toBe('d');
});

it('keeps internal vocabulary out of every summary', function (TaskStop $stop) {
    foreach (['—', 'sandbox', 'snapshot', 'template', '#'] as $forbidden) {
        expect($stop->summary)->not->toContain($forbidden);
    }
})->with([
    fn () => TaskStop::refreshingWorkspace('acme/app', 'd'),
    fn () => TaskStop::setupFailed('acme/app', 'd'),
    fn () => TaskStop::ciNeverReported(45, 'd'),
    fn () => TaskStop::ciStillFailing(3, ['lint'], 'd'),
    fn () => TaskStop::yakSideProblem('d'),
    fn () => TaskStop::deployPaused('d'),
    fn () => TaskStop::sizeLimit('d'),
    fn () => TaskStop::repositoryUnknown(['a/b'], 'd'),
    fn () => TaskStop::unansweredQuestion(Carbon::parse('2026-11-03'), 'd'),
]);
