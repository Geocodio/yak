<?php

use Symfony\Component\Finder\Finder;

/**
 * Guards App\Services\AgentJobDispatcher's role as the single choke point
 * for dispatching the four "claiming" agent jobs — RunYakJob, ResearchYakJob,
 * RunYakReviewJob, SetupYakJob. Every dispatch of one of these must go
 * through the helper so `dispatched_at`/`queue_job_uuid` can never drift
 * from what was actually queued (see AgentJobDispatcher's docblock).
 *
 * A plain source scan rather than pest-plugin-arch's dependency matchers:
 * arch()'s `toOnlyBeUsedIn()` treats any reference (a `use` import, a type
 * hint, a `instanceof` check) as "used", which is far broader than what
 * this test actually needs to catch — a direct `::dispatch(`/
 * `::dispatchSync(` call that bypasses the helper.
 */
test('the claiming agent jobs are only dispatched through AgentJobDispatcher', function () {
    $claimingJobs = ['RunYakJob', 'ResearchYakJob', 'RunYakReviewJob', 'SetupYakJob'];

    $pattern = '/\b(' . implode('|', $claimingJobs) . ')::dispatch(Sync|If|Unless|AfterResponse)?\s*\(/';

    $offenders = [];

    $files = Finder::create()
        ->in(app_path())
        ->name('*.php')
        // The helper itself is the one file allowed to call ::dispatch()
        // directly on these classes.
        ->notPath('Services/AgentJobDispatcher.php')
        ->files();

    foreach ($files as $file) {
        $contents = $file->getContents();

        if (preg_match($pattern, $contents, $matches)) {
            $offenders[] = $file->getRelativePathname() . ' (' . $matches[0] . ')';
        }
    }

    expect($offenders)->toBe([], 'Direct ::dispatch() call(s) on a claiming agent job outside AgentJobDispatcher: ' . implode(', ', $offenders));
});
