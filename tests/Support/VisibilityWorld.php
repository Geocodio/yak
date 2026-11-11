<?php

namespace Tests\Support;

use App\Enums\ThreadVisibility;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
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

    public YakTask $task;

    public YakTask $childTask;

    public TaskRun $run;

    public BranchDeployment $deployment;

    public TaskAttachment $attachment;

    public Artifact $artifact;

    public PrReview $prReview;

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

        $world->thread = Thread::factory()->create([
            'title' => self::TITLE,
            'repo' => self::REPO,
            'visibility' => $visibility,
            'owner_user_id' => $world->owner->id,
        ]);
        $world->child = Thread::factory()->child($world->thread)->create(['title' => self::TITLE . ' child']);

        $world->task = YakTask::factory()->create([
            'thread_id' => $world->thread->id,
            'repo' => self::REPO,
            'branch_name' => self::BRANCH,
            'description' => self::TITLE,
        ]);
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

        Storage::fake('artifacts');
        Storage::disk('artifacts')->put($world->artifact->disk_path, 'png');
        Storage::disk('artifacts')->put($world->attachment->disk_path, 'png');

        return $world;
    }
}
