<?php

use App\Channels\GitHub\AppService;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\VisibilityRoutes;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Bus::fake();
    Http::fake();
    Process::fake();
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([]);
    app()->instance(AppService::class, $github);
    config(['yak.deployments.hostname_suffix' => 'test']);
    // The catch-up window lives in Redis; the sweep reads the database fallback.
    $this->mock(ThreadEventWindow::class, fn ($window) => $window->shouldReceive('since')->andReturn(null));
});

/**
 * @param  array{method: string, uri: string, payload?: array<string, mixed>}  $probe
 */
function sweepRequest($test, array $probe, string $uri)
{
    return $test->call($probe['method'], $uri, $probe['payload'] ?? []);
}

dataset('probes', function () {
    foreach (VisibilityRoutes::probeNames() as $name) {
        yield $name => [$name];
    }
});

dataset('strangerProbes', function () {
    foreach (VisibilityRoutes::probeNames() as $name) {
        foreach (['other', 'admin'] as $stranger) {
            yield "{$name} as {$stranger}" => [$name, $stranger];
        }
    }
});

it('answers a hidden thread surface exactly like a missing id', function (string $name, string $stranger) {
    $world = VisibilityWorld::build();
    $probe = VisibilityRoutes::probes($world)[$name];

    $missing = sweepRequest($this->actingAs($world->{$stranger}), $probe, $probe['missingUri']);
    $hidden = sweepRequest($this->actingAs($world->{$stranger}), $probe, $probe['uri']);

    $normalise = fn (?string $text) => str_replace(
        [(string) $world->missingId, (string) $world->thread->id, (string) $world->task->id, (string) $world->deployment->id, (string) $world->attachment->id, (string) $world->prReview->pr_number, urlencode(VisibilityWorld::HOSTNAME), 'missing-preview.test', VisibilityWorld::HOSTNAME],
        '#',
        (string) $text,
    );

    expect($hidden->getStatusCode())->toBe($missing->getStatusCode())
        ->and($hidden->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'))
        ->and($normalise($hidden->headers->get('Location')))->toBe($normalise($missing->headers->get('Location')))
        ->and($normalise($hidden->getContent()))->toBe($normalise($missing->getContent()));

    // A PR review URL carries the repo slug itself, so the slug is the request, not a leak.
    $secrets = [VisibilityWorld::TITLE, VisibilityWorld::BRANCH, ...(str_contains($probe['uri'], VisibilityWorld::REPO) ? [] : [VisibilityWorld::REPO])];
    foreach ($secrets as $secret) {
        expect($hidden->getContent())->not->toContain($secret);
    }
})->with('strangerProbes');

it('lets the owner through the same route', function (string $name) {
    $probe = VisibilityRoutes::probes($world = VisibilityWorld::build())[$name];
    $expected = $probe['ownerStatus'] ?? null;

    $status = sweepRequest($this->actingAs($world->owner), $probe, $probe['uri'])->getStatusCode();

    $expected === null ? expect($status)->not->toBe(404)->and($status)->toBeLessThan(500) : expect($status)->toBe($expected);
})->with('probes');

it('lets a teammate through when the thread is team visible', function (string $name) {
    $probe = VisibilityRoutes::probes($world = VisibilityWorld::forTeamControl())[$name];
    $expected = $probe['ownerStatus'] ?? null;

    $status = sweepRequest($this->actingAs($world->other), $probe, $probe['uri'])->getStatusCode();

    $expected === null ? expect($status)->not->toBe(404)->and($status)->toBeLessThan(500) : expect($status)->toBe($expected);
})->with('probes');

it('owner reads a diff file and an event with 200', function (string $name) {
    $probe = VisibilityRoutes::probes($world = VisibilityWorld::build())[$name];

    sweepRequest($this->actingAs($world->owner), $probe, $probe['uri'])->assertOk();
})->with(['threads.diff.files.show', 'threads.events.show']);

it('does not serve a diff file that belongs to another thread', function () {
    $world = VisibilityWorld::build();
    $otherDiff = ThreadDiff::factory()->create(['thread_id' => $world->child->id, 'files_count' => 1]);
    $foreignFile = ThreadDiffFile::factory()->create(['thread_diff_id' => $otherDiff->id]);

    $this->actingAs($world->owner)->get("/threads/{$world->thread->id}/diff/files/{$foreignFile->id}")->assertNotFound();
});
