<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\MessageDelivery;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Resources\TaskDetailData;
use App\Http\Resources\ThreadCardsData;
use App\Http\Resources\ThreadMilestonesData;
use App\Http\Resources\ThreadRequestData;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunYakJob;
use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\Threads\ThreadMessageSender;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    $this->viewer = User::factory()->create(['name' => 'Rita Requester']);
});

function sharedRulesThread(YakTask $task): Thread
{
    return Thread::query()->findOrFail($task->thread_id);
}

/**
 * @return array<string, mixed>
 */
function sharedRulesActions(YakTask $task, User $viewer): array
{
    if ($task->thread_id === null) {
        $actions = TaskDetailData::actions($task, $task, null, false);

        return [...$actions, 'canMove' => $actions['canReroute'], 'moveTargets' => $actions['rerouteTargets']];
    }

    return ThreadCardsData::for(sharedRulesThread($task), $viewer)['actions'];
}

/**
 * @return array<string, mixed>
 */
function sharedRulesProps(Thread $thread, User $viewer): array
{
    return test()->actingAs($viewer)->get(route('threads.show', $thread))->assertOk()->inertiaProps();
}

describe('action flags', function () {
    it('lets a task with a pull request on a review-enabled repository request a review', function () {
        $repository = Repository::factory()->create(['slug' => 'org/reviewed', 'is_active' => true, 'pr_review_enabled' => true]);
        $task = YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/reviewed/pull/1']);

        $this->actingAs($this->viewer)->get(route('threads.show', sharedRulesThread($task)))
            ->assertInertia(fn (Assert $page) => $page->where('actions.canRequestReview', true));
    });

    it('offers no review without a pull request, for a review task, or with review off', function () {
        $enabled = Repository::factory()->create(['slug' => 'org/enabled', 'is_active' => true, 'pr_review_enabled' => true]);
        $disabled = Repository::factory()->create(['slug' => 'org/disabled', 'is_active' => true, 'pr_review_enabled' => false]);

        $tasks = [
            YakTask::factory()->create(['repo' => $enabled->slug, 'mode' => TaskMode::Fix, 'pr_url' => null]),
            YakTask::factory()->create(['repo' => $enabled->slug, 'mode' => TaskMode::Review, 'pr_url' => 'https://github.com/org/enabled/pull/2']),
            YakTask::factory()->create(['repo' => $disabled->slug, 'mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/disabled/pull/3']),
        ];

        foreach ($tasks as $task) {
            expect(sharedRulesActions($task, $this->viewer)['canRequestReview'])->toBeFalse();
        }
    });

    it('follows review.enabled from the repository config file', function () {
        fakeYakFiles(['config.yml' => "version: 1\nreview:\n  enabled: true\n"]);
        $repository = Repository::factory()->create(['slug' => 'org/filed', 'is_active' => true, 'pr_review_enabled' => false]);
        $task = YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/filed/pull/1']);

        expect(sharedRulesActions($task, $this->viewer)['canRequestReview'])->toBeTrue();
    });

    it('offers a render retry for a failed walkthrough render', function () {
        $repository = Repository::factory()->create(['slug' => 'org/render', 'is_active' => true]);
        $task = YakTask::factory()->create(['repo' => $repository->slug, 'pr_url' => 'https://github.com/org/render/pull/4']);
        Artifact::factory()->video()->create(['yak_task_id' => $task->id]);
        VideoMetric::factory()->failed()->create(['yak_task_id' => $task->id, 'error' => 'ffmpeg exited with 1']);

        expect(sharedRulesActions($task, $this->viewer)['canRetryRender'])->toBeTrue();

        $plain = YakTask::factory()->create(['repo' => $repository->slug, 'pr_url' => 'https://github.com/org/render/pull/5']);

        expect(sharedRulesActions($plain, $this->viewer)['canRetryRender'])->toBeFalse();
    });

    it('reports canRerunReview on the app API task detail, false for a follow-up review', function () {
        Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api']);
        $review = YakTask::factory()->create(['repo' => 'example/api', 'mode' => TaskMode::Review, 'status' => TaskStatus::Success, 'started_by_user_id' => $this->viewer->id, 'thread_id' => null]);

        $this->getJson(appTaskPath($review), phoneHeadersFor($this->viewer))->assertOk()
            ->assertJsonPath('actions.canRerunReview', true)
            ->assertJsonPath('actions.canRetry', false);

        YakTask::factory()->create(['repo' => 'example/api', 'mode' => TaskMode::Review, 'parent_task_id' => $review->id, 'thread_id' => null]);

        $this->getJson(appTaskPath($review->fresh()), phoneHeadersFor($this->viewer))->assertOk()
            ->assertJsonPath('actions.canRerunReview', false);
    });
});

describe('move targets', function () {
    it('lists the active repositories other than the current one, never an inactive one', function () {
        $current = Repository::factory()->create(['slug' => 'org/current', 'is_active' => true]);
        Repository::factory()->create(['slug' => 'org/other', 'is_active' => true]);
        Repository::factory()->inactive()->create(['slug' => 'org/inactive']);
        $task = YakTask::factory()->create(['repo' => $current->slug, 'mode' => TaskMode::Fix, 'pr_url' => null]);

        $actions = sharedRulesActions($task, $this->viewer);

        expect($actions['canMove'])->toBeTrue()->and($actions['moveTargets'])->toBe(['org/other']);
    });

    it('lists none for a setup task, a review task and a task with a pull request', function (array $attributes) {
        Repository::factory()->create(['slug' => 'org/current', 'is_active' => true]);
        Repository::factory()->create(['slug' => 'org/other', 'is_active' => true]);
        $task = YakTask::factory()->create(['repo' => 'org/current', 'pr_url' => null, ...$attributes]);

        $actions = sharedRulesActions($task, $this->viewer);

        expect($actions['canMove'])->toBeFalse()->and($actions['moveTargets'])->toBe([]);
    })->with([
        'setup' => [['mode' => TaskMode::Setup]],
        'review' => [['mode' => TaskMode::Review]],
        'pull request' => [['mode' => TaskMode::Fix, 'pr_url' => 'https://github.com/org/current/pull/1']],
    ]);
});

describe('research report milestone', function () {
    function sharedRulesReport(YakTask $task, string $filename = 'research.html'): void
    {
        Artifact::create(['yak_task_id' => $task->id, 'type' => 'research', 'role' => null, 'filename' => $filename, 'disk_path' => "{$task->id}/{$filename}", 'size_bytes' => 1]);
    }

    function sharedRulesRun(Thread $thread, YakTask $task, int $sequence): void
    {
        $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'commits' => 0]);
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 1]);
    }

    it('ends the report milestones with the newest report of the conversation', function () {
        $thread = Thread::factory()->create();
        $first = YakTask::factory()->success()->create(['thread_id' => $thread->id, 'mode' => TaskMode::Research]);
        sharedRulesReport($first, 'first.html');
        sharedRulesRun($thread, $first, 1);
        $second = YakTask::factory()->success()->create(['thread_id' => $thread->id, 'mode' => TaskMode::Research, 'parent_task_id' => $first->id]);
        sharedRulesReport($second, 'second.html');
        sharedRulesRun($thread, $second, 3);

        $report = collect(ThreadMilestonesData::for($thread))->where('key', 'research_report');

        expect($report->last()['url'])->toContain($second->public_id)->toContain('/viewer/second.html')
            ->and($report->first()['url'])->toContain('/viewer/first.html');
    });

    it('has no report milestone without a report or for a task that is not research', function () {
        $research = Thread::factory()->create();
        $researchTask = YakTask::factory()->success()->create(['thread_id' => $research->id, 'mode' => TaskMode::Research]);
        sharedRulesRun($research, $researchTask, 1);
        $fix = Thread::factory()->create();
        $fixTask = YakTask::factory()->success()->create(['thread_id' => $fix->id, 'mode' => TaskMode::Fix]);
        sharedRulesReport($fixTask);
        sharedRulesRun($fix, $fixTask, 1);

        expect(ThreadMilestonesData::for($research))->not->toBeEmpty()
            ->and(collect(ThreadMilestonesData::for($research))->pluck('key')->all())->not->toContain('research_report')
            ->and(collect(ThreadMilestonesData::for($fix))->pluck('key')->all())->not->toContain('research_report');
    });
});

describe('early warning', function () {
    beforeEach(function () {
        $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
        $this->task = YakTask::factory()->create([
            'repo' => 'o-r',
            'started_by_user_id' => $this->viewer->id,
            'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown'],
        ]);
        $this->thread = sharedRulesThread($this->task);
    });

    function sharedRulesGrant(Repository $repository, User $user, string $access = 'write'): void
    {
        Cache::put("co-owner:write-access:{$repository->id}:{$user->github_id}", $access, now()->addMinutes(10));
    }

    it('carries the repository name, the areas and no tagged name', function () {
        $cards = ThreadCardsData::for($this->thread, $this->viewer);

        expect($cards['earlyWarning'])->toMatchArray(['state' => 'shown', 'repoName' => 'o/r', 'canPick' => true, 'taggedName' => null])
            ->and($cards['earlyWarning']['areas'][0]['name'])->toBe('Billing');
    });

    it('names the tagged person and reads a deleted tagged user as untagged', function () {
        $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
        $this->task->update(['early_warning' => [...$this->task->early_warning, 'state' => 'tagged'], 'tagged_co_owner_user_id' => $writer->id]);

        expect(ThreadCardsData::for($this->thread, $this->viewer)['earlyWarning'])->toMatchArray(['state' => 'tagged', 'taggedName' => 'Wanda Writer', 'canPick' => false]);

        $this->task->update(['tagged_co_owner_user_id' => null]);

        expect(ThreadCardsData::for($this->thread, $this->viewer)['earlyWarning'])->toMatchArray(['state' => 'shown', 'taggedName' => null]);
    });

    it('lists only linked people with write access, not the requester, in the order of their names', function () {
        $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
        $reader = User::factory()->withGitHub(2, 'b')->create(['name' => 'Rex Reader']);
        User::factory()->create(['name' => 'Unlinked Una']);
        $this->viewer->update(['github_id' => 999, 'github_login' => 'rita']);
        sharedRulesGrant($this->repository, $writer);
        sharedRulesGrant($this->repository, $reader, 'read');
        sharedRulesGrant($this->repository, $this->viewer);

        $this->actingAs($this->viewer)->getJson(route('threads.co-owner.candidates', $this->thread))
            ->assertOk()
            ->assertExactJson(['candidates' => [['id' => $writer->id, 'name' => 'Wanda Writer']], 'isComplete' => true]);
    });

    it('scans at most 25 linked people', function () {
        foreach (range(1, 26) as $number) {
            $user = User::factory()->withGitHub(100 + $number, "u{$number}")->create(['name' => sprintf('User %02d', $number)]);
            sharedRulesGrant($this->repository, $user);
        }

        $candidates = $this->actingAs($this->viewer)->getJson(route('threads.co-owner.candidates', $this->thread))->assertOk()->json('candidates');

        expect($candidates)->toHaveCount(25)
            ->and($candidates[0]['name'])->toBe('User 01')
            ->and($candidates[24]['name'])->toBe('User 25');
    });
});

describe('co-owner request states', function () {
    beforeEach(function () {
        $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
        $this->task = YakTask::factory()->awaitingCoOwner()->create([
            'repo' => 'o-r', 'branch_name' => 'yak/x', 'source' => 'slack', 'started_by_user_id' => $this->viewer->id,
        ]);
        $this->request = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id, 'asked_user_ids' => [$this->viewer->id]]);
        $this->thread = sharedRulesThread($this->task);
        Http::swap(new Factory);
        Http::fake(['api.github.com/*' => Http::response(['role_name' => 'write'])]);
    });

    it('says an expired request expired and offers no retry', function () {
        $this->request->update(['status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
        $this->task->update(['status' => TaskStatus::Expired]);

        $cards = ThreadCardsData::for($this->thread, $this->viewer);

        expect($cards['coOwner']['status'])->toBe('expired')
            ->and($cards['actions']['canRetry'])->toBeFalse()
            ->and($cards['card'])->toBe('stopped')
            ->and($cards['stopped'])->toBe([
                'kind' => 'needs_you',
                'summary' => 'Nobody decided within 7 days, so Yak did not open the pull request.',
                'detail' => null,
                'nextStep' => 'Send a message to continue this work.',
                'isWaitingForSetup' => false,
            ]);
    });

    it('starts a follow-up when a message is sent to a thread whose co-owner request expired', function () {
        $this->request->update(['status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
        $this->task->update(['status' => TaskStatus::Expired, 'pr_url' => null]);

        $result = app(ThreadMessageSender::class)->send($this->thread, $this->viewer, new ThreadMessageInput('Go ahead without a co-owner', null, null, null, MessageDelivery::Steer, null));

        expect($result->notice)->toBe('Trying again with your note.')
            ->and($this->task->fresh()->status)->toBe(TaskStatus::Pending);
        Queue::assertPushed(RunYakJob::class);
    });

    it('says on the app API task detail that an expired co-owner request needs a new task', function () {
        $this->request->update(['status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
        $this->task->update(['status' => TaskStatus::Expired]);

        $this->getJson(appTaskPath($this->task), phoneHeadersFor($this->viewer))->assertOk()
            ->assertJsonPath('task.nextSteps', 'The co-owner request expired after 7 days. Start a new task to try again.')
            ->assertJsonPath('actions.canRetry', false);
    });

    it('treats the expired request of a follow-up as the thread request and offers no retry', function () {
        $this->request->update(['status' => CoOwnerRequestStatus::ChangesRequested]);
        $this->task->update(['status' => TaskStatus::Success]);
        $followUp = YakTask::factory()->expired()->create([
            'repo' => 'o-r', 'parent_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'source' => 'slack', 'branch_name' => 'yak/x',
        ]);
        CoOwnerRequest::factory()->create([
            'task_id' => $followUp->id, 'root_task_id' => $this->task->id, 'repository_id' => $this->repository->id, 'status' => CoOwnerRequestStatus::Expired,
        ]);

        $cards = ThreadCardsData::for($this->thread, $this->viewer);

        expect($cards['coOwner']['status'])->toBe('expired')->and($cards['actions']['canRetry'])->toBeFalse();
    });

    it('serves the latest request of a request-changes chain', function () {
        $this->request->update(['status' => CoOwnerRequestStatus::ChangesRequested]);
        $this->task->update(['status' => TaskStatus::Success]);
        $followUp = YakTask::factory()->awaitingCoOwner()->create([
            'repo' => 'o-r', 'parent_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'source' => 'slack', 'branch_name' => 'yak/x', 'started_by_user_id' => $this->viewer->id,
        ]);
        $latest = CoOwnerRequest::factory()->create(['task_id' => $followUp->id, 'root_task_id' => $this->task->id, 'repository_id' => $this->repository->id]);

        $cards = ThreadCardsData::for($this->thread, $this->viewer);

        expect($cards['coOwner']['id'])->toBe($latest->id)->and($cards['card'])->toBe('co_owner');
    });

    it('looks up a Slack requester without a Yak account once across renders', function () {
        config()->set('yak.channels.slack.bot_token', 'xoxb-test');
        $this->task->update(['started_by_user_id' => null, 'slack_user_id' => 'UGHOSTREQ']);
        Http::fake(['https://slack.com/api/*' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

        $this->actingAs($this->viewer)->get(route('threads.show', $this->thread))->assertOk();
        $this->get(route('threads.show', $this->thread))->assertOk();

        expect(Http::recorded(fn ($request): bool => str_contains($request->url(), 'users.info')))->toHaveCount(1);
    });
});

describe('progress and stop card', function () {
    /**
     * @return array<string, array{label: string, state: string}>
     */
    function sharedRulesSteps(YakTask $task): array
    {
        return collect(ThreadRequestData::for(sharedRulesThread($task), null)['progress']['steps'])->keyBy('label')->all();
    }

    it('reads the CI step as waiting until CI passes', function () {
        $task = YakTask::factory()->awaitingCi()->create();

        expect(sharedRulesSteps($task)['Waiting for CI']['state'])->toBe('current');
    });

    it('turns the CI step red while Yak retries after a CI failure', function () {
        $task = YakTask::factory()->create(['status' => TaskStatus::Retrying]);
        TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE, 'metadata' => ['passed' => false]]);

        expect(sharedRulesSteps($task)['CI failed, retrying']['state'])->toBe('failed');

        $task->update(['status' => TaskStatus::AwaitingCi]);

        expect(sharedRulesSteps($task)['Waiting for CI']['state'])->not->toBe('failed');
    });

    it('marks CI failed when the task stopped after a failed result', function () {
        $task = YakTask::factory()->failed()->create(['branch_name' => 'yak/x']);
        TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE, 'metadata' => ['passed' => false]]);

        expect(sharedRulesSteps($task)['CI failed']['state'])->toBe('failed');
    });

    it('shows CI passed done and the co-owner wait as current', function () {
        $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
        $task = YakTask::factory()->awaitingCoOwner()->create(['repo' => 'o-r']);
        CoOwnerRequest::factory()->create(['task_id' => $task->id, 'repository_id' => $repository->id]);

        $steps = sharedRulesSteps($task);

        expect($steps['CI passed']['state'])->toBe('done')
            ->and($steps['Waiting for a co-owner']['state'])->toBe('current')
            ->and($steps['Pull request']['state'])->toBe('upcoming');
    });

    it('points a needs-you stop at the answer below', function () {
        $task = YakTask::factory()->failed()->create(['source' => 'dashboard', 'stop_kind' => StopKind::NeedsYou, 'stop_summary' => 'Yak asked a question and did not hear back.']);

        $stopped = ThreadCardsData::for(sharedRulesThread($task), $this->viewer)['stopped'];

        expect($stopped)->toMatchArray(['kind' => 'needs_you', 'nextStep' => 'Write your answer below and send it. Yak tries again with it.']);
    });

    it('leads a stop with the plain summary and keeps the technical text as detail', function () {
        $task = YakTask::factory()->failed()->create([
            'source' => 'linear', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'The automated checks did not report back in time.', 'error_log' => 'CI timed out after 30 minutes',
        ]);

        expect(ThreadCardsData::for(sharedRulesThread($task), $this->viewer)['stopped'])->toMatchArray([
            'kind' => 'yak_stopped',
            'summary' => 'The automated checks did not report back in time.',
            'detail' => 'CI timed out after 30 minutes',
            'nextStep' => 'Reply in the Linear issue, or press Retry, and Yak will try again.',
            'isWaitingForSetup' => false,
        ]);
    });

    it('shows a failed task waiting on setup as paused', function () {
        $task = YakTask::factory()->failed()->create(['stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Something stopped the run.', 'resume_after_setup_at' => now()]);

        expect(ThreadCardsData::for(sharedRulesThread($task), $this->viewer)['stopped'])->toMatchArray([
            'kind' => 'yak_retrying', 'isWaitingForSetup' => true, 'nextStep' => 'Yak picks it back up on its own. Nothing needed from you.',
        ]);
    });

    it('serves the stop of an expired needs-you task and none for a running one', function () {
        $expired = YakTask::factory()->expired()->create([
            'source' => 'linear', 'stop_kind' => StopKind::NeedsYou, 'stop_summary' => 'Yak asked a question and did not hear back, so it stopped.',
            'error_log' => 'Clarification expired, no response received',
        ]);
        $running = YakTask::factory()->running()->create();

        $cards = ThreadCardsData::for(sharedRulesThread($expired), $this->viewer);

        expect($cards['stopped'])->toMatchArray(['kind' => 'needs_you', 'summary' => 'Yak asked a question and did not hear back, so it stopped.'])
            ->and($cards['stopped']['kind'])->not->toBe(StopKind::YakStopped->value)
            ->and($cards['card'])->toBe('stopped')
            ->and(ThreadCardsData::for(sharedRulesThread($running), $this->viewer)['stopped'])->toBeNull();
    });
});

describe('thread log infinite scroll', function () {
    it('serves the rows as a scroll prop that merges pages and matches rows by id', function () {
        Thread::factory()->count(51)->create();
        $viewer = User::factory()->create();
        $version = $this->actingAs($viewer)->get(route('threads.log'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

        $response = $this->get(route('threads.log', ['page' => 2]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Data' => 'rows',
            'X-Inertia-Partial-Component' => 'Threads/Log',
        ])->assertOk()->json();

        expect($response['mergeProps'] ?? [])->toContain('rows.data')
            ->and($response['matchPropsOn'] ?? [])->toContain('rows.data.id')
            ->and($response['scrollProps']['rows'])->toMatchArray(['pageName' => 'page', 'previousPage' => 1, 'nextPage' => null, 'currentPage' => 2])
            ->and($response['props']['rows']['data'])->toHaveCount(1);
    });

    it('prepends a poll reload of page 1 and does not drop the loaded pages', function () {
        Thread::factory()->count(3)->create();
        $viewer = User::factory()->create();
        $version = $this->actingAs($viewer)->get(route('threads.log'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

        $response = $this->get(route('threads.log'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Data' => 'rows,counts,facets',
            'X-Inertia-Partial-Component' => 'Threads/Log',
            'X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ])->assertOk()->json();

        expect($response['prependProps'] ?? [])->toContain('rows.data')
            ->and($response['mergeProps'] ?? [])->not->toContain('rows.data')
            ->and($response['matchPropsOn'] ?? [])->toContain('rows.data.id')
            ->and($response['scrollProps']['rows']['currentPage'])->toBe(1);
    });
});

describe('review context on the app API task detail', function () {
    beforeEach(function () {
        Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api']);
    });

    it('builds the review context turn from the task context', function () {
        $task = YakTask::factory()->create([
            'repo' => 'example/api', 'mode' => TaskMode::Review, 'thread_id' => null, 'started_by_user_id' => $this->viewer->id,
            'context' => json_encode(['pr_number' => 42, 'author' => 'octocat', 'title' => 'Fix the flaky test', 'body' => 'This stabilizes the retry logic.']),
        ]);

        $entry = $this->getJson(appTaskPath($task), phoneHeadersFor($this->viewer))->assertOk()->json('thread.0');

        expect($entry)->toMatchArray(['kind' => 'review-context', 'who' => 'Fix the flaky test'])
            ->and($entry['meta'])->toContain('PR #42')->toContain('octocat')
            ->and($entry['bodyHtml'])->toContain('stabilizes the retry logic');
    });

    it('points uploaded pull request images at signed app links, never at GitHub', function () {
        $asset = 'f38ea4ee-97ce-48fc-a48e-a049c20f7cd4';
        $task = YakTask::factory()->create([
            'repo' => 'example/api', 'mode' => TaskMode::Review, 'thread_id' => null, 'started_by_user_id' => $this->viewer->id,
            'context' => json_encode(['pr_number' => 42, 'body' => "![Screenshot](https://github.com/user-attachments/assets/{$asset})"]),
        ]);

        $html = $this->getJson(appTaskPath($task), phoneHeadersFor($this->viewer))->assertOk()->json('thread.0.bodyHtml');

        expect($html)->toContain('app-media/tasks/')->toContain("/pr-images/{$asset}")->not->toContain('github.com/user-attachments');
    });
});
