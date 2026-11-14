<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadVisibility;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\RenderWalkthroughJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\PullRequestBodySections;
use App\Services\PullRequestBodyUpdater;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadArtifacts;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
    $this->withoutVite();
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'visibility' => ThreadVisibility::Private, 'owner_user_id' => $this->owner->id]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
});

/** Queues the sandbox answers for a `.yak-artifacts` folder holding the given files. */
function queueSandboxFolder(FakeSandboxManager $sandboxes, array $files): void
{
    $source = sys_get_temp_dir() . '/yak-shared-' . uniqid();

    foreach ($files as $name => $content) {
        File::ensureDirectoryExists(dirname("{$source}/.yak-artifacts/{$name}"));
        File::put("{$source}/.yak-artifacts/{$name}", $content);
    }

    $archive = Process::run(['tar', '-cf', '-', '-C', $source, '.yak-artifacts'])->output();
    File::deleteDirectory($source);

    $sandboxes->queueExecResult(Process::result('', '', 0));
    $sandboxes->queueExecResult(Process::result(base64_encode($archive)));
}

function pngWithSeed(int $seed): string
{
    $image = imagecreatetruecolor(16, 16);
    for ($x = 0; $x < 16; $x++) {
        for ($y = 0; $y < 16; $y++) {
            $value = ($x * 16 + $y * $seed * 7) % 256;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $value, ($value * $seed) % 256, 255 - $value));
        }
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function putSharedFile(YakTask $task, string $name, string $content = 'data'): Artifact
{
    Storage::disk('artifacts')->put("{$task->id}/shared/{$name}", $content);

    return Artifact::factory()->shared($name)->create([
        'yak_task_id' => $task->id,
        'disk_path' => "{$task->id}/shared/{$name}",
    ]);
}

describe('collection', function () {
    it('maps the shared folder to the shared role with a public id and no typed side effects', function () {
        queueSandboxFolder($this->sandboxes, ['shared/report.html' => '<h1>Hi</h1>', 'shared/video.mp4' => 'mp4', 'shared/chart.png' => 'png']);
        ThreadEvent::factory()->create(['thread_id' => $this->thread->id, 'sequence' => 7]);

        app(ThreadArtifacts::class)->collect($this->thread);

        $files = $this->task->artifacts()->shared()->get();
        expect(ArtifactPersister::SUBDIRECTORY_ROLES['shared'])->toBe('shared')
            ->and($files->pluck('filename')->sort()->values()->all())->toBe(['chart.png', 'report.html', 'video.mp4'])
            ->and($files->every(fn (Artifact $file) => strlen($file->public_id) === 26 && $file->type === 'file' && $file->anchor_sequence === 7 && ! $file->shared_with_team))->toBeTrue()
            ->and(Storage::disk('artifacts')->exists("{$this->task->id}/shared/report.html"))->toBeTrue();
        Queue::assertNotPushed(RenderVideoJob::class);
        Queue::assertNotPushed(RenderWalkthroughJob::class);
    });

    it('keeps every shared image outside the screenshot cap and the walkthrough', function () {
        $files = ['shared/c0.png' => pngWithSeed(1)];
        foreach (range(1, 6) as $index) {
            $files["shared/chart-{$index}.png"] = pngWithSeed($index + 1);
        }
        queueSandboxFolder($this->sandboxes, $files + ['screenshots/form.png' => pngWithSeed(9)]);

        app(ThreadArtifacts::class)->collect($this->thread);

        expect($this->task->artifacts()->shared()->count())->toBe(7)
            ->and($this->task->artifacts()->role('screenshot')->pluck('filename')->all())->toBe(['form.png']);
    });

    it('keeps shared files out of the pull request body', function () {
        config()->set('yak.channels.github.installation_id', 77);
        Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main']);
        $task = YakTask::factory()->create(['repo' => 'acme/web', 'branch_name' => 'yak/census', 'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9, 'result_summary' => 'Summary']);
        Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'form.png', 'disk_path' => "{$task->id}/form.png", 'size_bytes' => 1]);
        $shared = putSharedFile($task, 'chart.png');

        $github = $this->mock(GitHubAppService::class);
        $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9']);
        $captured = null;
        $this->mock(PullRequestBodyUpdater::class)->shouldReceive('setSections')->once()
            ->withArgs(function (string $repo, int $number, array $sections) use (&$captured) {
                $captured = $sections[PullRequestBodySections::SCREENSHOTS] ?? '';

                return true;
            })->andReturn([PullRequestBodySections::SCREENSHOTS]);

        (new CreatePullRequestJob($task, refreshOnly: true))->handle($github);

        expect($captured)->toContain('form.png')->not->toContain('chart.png')->not->toContain($shared->public_id);
    });

    it('lists shared files with the right fields and never lists walkthrough files', function () {
        $report = putSharedFile($this->task, 'report.html');
        $report->update(['anchor_sequence' => 4, 'shared_with_team' => true]);
        Artifact::create(['yak_task_id' => $this->task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'form.png', 'disk_path' => 'form.png', 'size_bytes' => 1]);
        foreach (['script', 'manifest', 'shot', 'still', 'voiceover', 'cut', 'thumbnail', 'raw'] as $role) {
            Artifact::create(['yak_task_id' => $this->task->id, 'type' => 'file', 'role' => $role, 'filename' => "{$role}.bin", 'disk_path' => "{$role}.bin", 'size_bytes' => 1]);
        }

        $this->actingAs($this->owner)->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
            ->has('sharedFiles', 1)
            ->where('sharedFiles.0.id', $report->public_id)
            ->where('sharedFiles.0.name', 'report.html')
            ->where('sharedFiles.0.kind', 'report')
            ->where('sharedFiles.0.sizeBytes', 2048)
            ->where('sharedFiles.0.sharedWithTeam', true)
            ->where('sharedFiles.0.anchorSequence', 4)
            ->where('sharedFiles.0.url', route('shared-files.show', $report->public_id)));
    });
});

describe('pruning', function () {
    it('keeps shared files when a newer recording replaces the old one', function () {
        $shared = putSharedFile($this->task, 'report.html');
        Storage::disk('artifacts')->put("{$this->task->id}/script.json", 'old');
        Artifact::create(['yak_task_id' => $this->task->id, 'type' => 'file', 'role' => 'script', 'filename' => 'script.json', 'disk_path' => "{$this->task->id}/script.json", 'size_bytes' => 3]);

        queueSandboxFolder($this->sandboxes, ['script.json' => 'new', 'manifest.json' => json_encode(['version' => 3])]);
        app(ThreadArtifacts::class)->collect($this->thread);

        expect(Artifact::find($shared->id))->not->toBeNull()
            ->and(Storage::disk('artifacts')->exists($shared->disk_path))->toBeTrue()
            ->and($this->task->artifacts()->role('script')->count())->toBe(1);
    });

    it('keeps earlier shared files when a later turn shares another', function () {
        queueSandboxFolder($this->sandboxes, ['shared/first.csv' => 'a,b']);
        app(ThreadArtifacts::class)->collect($this->thread);
        queueSandboxFolder($this->sandboxes, ['shared/second.csv' => 'c,d']);
        app(ThreadArtifacts::class)->collect($this->thread);

        expect($this->task->artifacts()->shared()->orderBy('id')->pluck('filename')->all())->toBe(['first.csv', 'second.csv']);
    });
});

describe('access to files/{publicId}', function () {
    beforeEach(function () {
        $this->file = putSharedFile($this->task, 'report.html', '<h1>Report</h1>');
        $this->stranger = User::factory()->create();
    });

    it('lets a thread reader open the file when it is not shared', function () {
        $this->actingAs($this->owner)->get("/files/{$this->file->public_id}")->assertOk()->assertSee('report.html');
        $this->actingAs($this->owner)->get("/files/{$this->file->public_id}/raw")->assertOk()->assertHeader('Content-Security-Policy', 'sandbox');
    });

    it('lets a person the private thread is shared with open it', function () {
        $reader = User::factory()->create();
        ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)->get("/files/{$this->file->public_id}")->assertOk();
    });

    it('answers a non-reader like a missing file while it is not shared', function () {
        $hidden = $this->actingAs($this->stranger)->get("/files/{$this->file->public_id}");
        $missing = $this->actingAs($this->stranger)->get('/files/' . str_repeat('0', 26));

        expect($hidden->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
            ->and($hidden->getContent())->toBe($missing->getContent());
        $this->actingAs($this->stranger)->get("/files/{$this->file->public_id}/raw")->assertNotFound();
    });

    it('shows the file alone to any signed-in user once it is shared, with no thread title or link', function () {
        $this->file->update(['shared_with_team' => true]);
        $this->thread->update(['title' => 'Secret layoff plan']);

        $response = $this->actingAs($this->stranger)->get("/files/{$this->file->public_id}")->assertOk();
        $response->assertSee('Shared with the team')->assertDontSee('Secret layoff plan')->assertDontSee("/threads/{$this->thread->public_id}");
        $raw = $this->actingAs($this->stranger)->get("/files/{$this->file->public_id}/raw")->assertOk();
        expect(file_get_contents($raw->baseResponse->getFile()->getPathname()))->toBe('<h1>Report</h1>');
    });

    it('shows images inline and downloads other types', function () {
        $image = putSharedFile($this->task, 'chart.png', 'png');
        $csv = putSharedFile($this->task, 'results.csv', 'a,b');
        $this->owner->refresh();

        $this->actingAs($this->owner)->get("/files/{$image->public_id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($this->actingAs($this->owner)->get("/files/{$image->public_id}")->headers->get('Content-Disposition'))->toStartWith('inline');
        expect($this->actingAs($this->owner)->get("/files/{$csv->public_id}")->headers->get('Content-Disposition'))->toStartWith('attachment');
    });

    it('sends a guest to sign in', function () {
        $this->file->update(['shared_with_team' => true]);

        $this->get("/files/{$this->file->public_id}")->assertRedirect();
    });

    it('does not serve a file whose bytes are gone', function () {
        Storage::disk('artifacts')->delete($this->file->disk_path);

        $this->actingAs($this->owner)->get("/files/{$this->file->public_id}")->assertNotFound();
    });

    it('does not serve a walkthrough file through the shared route', function () {
        $shot = Artifact::factory()->screenshot()->create(['yak_task_id' => $this->task->id, 'public_id' => str_repeat('a', 26), 'shared_with_team' => true]);

        $this->actingAs($this->stranger)->get("/files/{$shot->public_id}")->assertNotFound();
    });
});

describe('team share toggle', function () {
    beforeEach(function () {
        $this->file = putSharedFile($this->task, 'report.html');
        $this->url = "/threads/{$this->thread->public_id}/files/{$this->file->public_id}/team-share";
    });

    it('lets the owner turn sharing on and off', function () {
        $this->actingAs($this->owner)->putJson($this->url, ['shared' => true])->assertOk()->assertJson(['sharedWithTeam' => true]);
        expect($this->file->fresh()->shared_with_team)->toBeTrue()->and($this->file->fresh()->shared_at)->not->toBeNull();

        $this->actingAs($this->owner)->putJson($this->url, ['shared' => false])->assertOk();
        expect($this->file->fresh()->shared_with_team)->toBeFalse()->and($this->file->fresh()->shared_at)->toBeNull();
    });

    it('refuses a reader who cannot drive a private thread', function () {
        $reader = User::factory()->create();
        ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)->putJson($this->url, ['shared' => true])->assertForbidden();
        expect($this->file->fresh()->shared_with_team)->toBeFalse();
    });

    it('hides the thread from a stranger', function () {
        $this->actingAs(User::factory()->create())->putJson($this->url, ['shared' => true])->assertNotFound();
    });

    it('lets a teammate drive a team thread', function () {
        $this->thread->update(['visibility' => ThreadVisibility::Team]);

        $this->actingAs(User::factory()->create())->putJson($this->url, ['shared' => true])->assertOk();
        expect($this->file->fresh()->shared_with_team)->toBeTrue();
    });

    it('404s a file that is not on the thread', function () {
        $other = putSharedFile(YakTask::factory()->create(), 'other.html');

        $this->actingAs($this->owner)->putJson("/threads/{$this->thread->public_id}/files/{$other->public_id}/team-share", ['shared' => true])->assertNotFound();
    });
});

it('tells Claude where to save shared files', function () {
    $prompt = YakPromptBuilder::threadSystemPrompt($this->task, $this->thread);

    expect($prompt)->toContain('SHARED FILES')->toContain('.yak-artifacts/shared/')->toContain('Do not mention the folder path');
});
