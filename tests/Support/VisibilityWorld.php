<?php

namespace Tests\Support;

use App\Enums\ThreadVisibility;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\ClaudeAccount;
use App\Models\CoOwnerRequest;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadEvent;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

/**
 * One private thread with a child, tasks, a run, a preview deployment and an
 * attachment, plus the people who must not see any of it. The visibility
 * sweep probes every surface against this graph.
 */
class VisibilityWorld
{
    public const TITLE = 'Quarterly layoff plan spreadsheet';

    public const REPO = 'secret-acme/payroll';

    public const BRANCH = 'yak/secret-layoff-branch';

    public const HOSTNAME = 'private-preview.test';

    public User $owner;

    public User $other;

    public User $admin;

    public Thread $thread;

    public Thread $child;

    public ThreadRepository $pullRequestRow;

    public YakTask $task;

    public YakTask $childTask;

    public TaskRun $run;

    public BranchDeployment $deployment;

    public TaskAttachment $attachment;

    public Artifact $artifact;

    public PrReview $prReview;

    public CoOwnerRequest $coOwnerRequest;

    public ThreadDiffFile $diffFile;

    public ThreadEvent $event;

    public ThreadRepository $repository;

    public int $missingId = 999_999;

    public static function build(): self
    {
        return self::make(ThreadVisibility::Private);
    }

    /**
     * The same graph with team visibility: proves the owner-visible baseline.
     */
    public static function forTeamControl(): self
    {
        return self::make(ThreadVisibility::Team);
    }

    private static function make(ThreadVisibility $visibility): self
    {
        $world = new self;
        $world->owner = User::factory()->create();
        $world->other = User::factory()->create();
        $world->admin = User::factory()->create();
        config(['claude-accounts.admin_user_ids' => [$world->admin->id]]);

        $account = ClaudeAccount::factory()->create();
        $world->thread = Thread::factory()->create([
            'claude_account_id' => $account->id,
            'title' => self::TITLE,
            'repo' => self::REPO,
            'visibility' => $visibility,
            'owner_user_id' => $world->owner->id,
        ]);
        $diff = ThreadDiff::factory()->create(['thread_id' => $world->thread->id, 'files_count' => 1]);
        $world->diffFile = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id]);
        $world->repository = ThreadRepository::factory()->changes()->create(['thread_id' => $world->thread->id, 'repo' => 'secret-acme/extra']);
        $world->event = ThreadEvent::factory()->create(['thread_id' => $world->thread->id, 'sequence' => 1]);
        $world->pullRequestRow = ThreadRepository::factory()->for($world->thread)->changes()->withPullRequest(77)->create(['repo' => self::REPO]);
        $world->child = Thread::factory()->child($world->thread)->create(['title' => self::TITLE . ' child', 'claude_account_id' => $account->id]);

        $world->task = YakTask::factory()->create([
            'thread_id' => $world->thread->id,
            'repo' => self::REPO,
            'branch_name' => self::BRANCH,
            'description' => self::TITLE,
            'pr_url' => 'https://github.com/' . self::REPO . '/pull/77',
            'pr_number' => 77,
        ]);
        $repository = Repository::factory()->create(['slug' => self::REPO, 'github_full_name' => self::REPO, 'is_active' => true, 'pr_review_enabled' => true]);
        $world->childTask = YakTask::factory()->create([
            'thread_id' => $world->child->id,
            'repo' => self::REPO,
            'description' => self::TITLE . ' child',
        ]);
        $world->run = TaskRun::factory()->create(['yak_task_id' => $world->task->id, 'repo' => self::REPO]);
        $world->deployment = BranchDeployment::factory()->running()->create([
            'yak_task_id' => $world->task->id,
            'hostname' => self::HOSTNAME,
            'branch_name' => self::BRANCH,
        ]);
        $world->attachment = TaskAttachment::factory()->create(['yak_task_id' => $world->task->id]);
        $world->artifact = Artifact::factory()->create(['yak_task_id' => $world->task->id, 'filename' => 'shot.png', 'disk_path' => 'private/shot.png']);
        $world->prReview = PrReview::factory()->create(['yak_task_id' => $world->task->id, 'repo' => self::REPO, 'pr_number' => 77]);

        $world->coOwnerRequest = CoOwnerRequest::factory()->create([
            'task_id' => $world->task->id,
            'root_task_id' => $world->task->id,
            'repository_id' => $repository->id,
        ]);

        Storage::fake('artifacts');
        Storage::disk('artifacts')->put($world->artifact->disk_path, 'png');
        Storage::disk('artifacts')->put($world->attachment->disk_path, 'png');

        return $world;
    }
}
