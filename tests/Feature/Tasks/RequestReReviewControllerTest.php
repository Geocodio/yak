<?php

use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 12345);
});

function makeReviewTask(array $overrides = []): YakTask
{
    Repository::factory()->create([
        'slug' => 'geocodio/api',
        'is_active' => true,
        'pr_review_enabled' => true,
        'git_url' => 'https://github.com/geocodio/api.git',
        ...($overrides['repo'] ?? []),
    ]);

    return YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
        'external_id' => 'https://github.com/geocodio/api/pull/77',
        'context' => json_encode([
            'pr_number' => 77,
            'head_sha' => 'old-sha',
            'base_sha' => 'base-sha',
            'review_scope' => 'full',
            'incremental_base_sha' => null,
        ]),
        ...($overrides['task'] ?? []),
    ]);
}

function fakeOpenPr(array $overrides = []): void
{
    $pr = array_replace_recursive([
        'html_url' => 'https://github.com/geocodio/api/pull/77',
        'number' => 77,
        'title' => 'Some PR',
        'body' => '',
        'state' => 'open',
        'draft' => false,
        'user' => ['login' => 'mathias'],
        'head' => ['ref' => 'feat/x', 'sha' => 'new-head-sha'],
        'base' => ['ref' => 'main', 'sha' => 'base-sha'],
    ], $overrides);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/geocodio/api/pulls/77' => Http::response($pr),
    ]);
}

it('redirects unauthenticated visitors to login', function () {
    $task = makeReviewTask();

    $this->post(route('tasks.re-request-review', $task))
        ->assertRedirect(route('login'));
});

it('dispatches an incremental review when a prior review exists, then redirects to the new task', function () {
    $task = makeReviewTask();

    PrReview::create([
        'yak_task_id' => $task->id,
        'repo' => 'geocodio/api',
        'pr_number' => 77,
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
        'commit_sha_reviewed' => 'previously-reviewed-sha',
        'review_scope' => 'full',
        'summary' => 's', 'verdict' => 'Approve',
        'submitted_at' => now(),
    ]);

    fakeOpenPr();

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertRedirect()
        ->assertSessionHas('reReview', 'started');

    $new = YakTask::query()
        ->where('mode', TaskMode::Review)
        ->where('id', '!=', $task->id)
        ->latest('id')
        ->first();

    expect($new)->not->toBeNull();
    $ctx = json_decode((string) $new->context, true);
    expect($ctx['head_sha'])->toBe('new-head-sha')
        ->and($ctx['review_scope'])->toBe('incremental')
        ->and($ctx['incremental_base_sha'])->toBe('previously-reviewed-sha');
});

it('falls back to a full review when no prior review is on file', function () {
    $task = makeReviewTask();
    fakeOpenPr();

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertRedirect();

    $new = YakTask::query()
        ->where('mode', TaskMode::Review)
        ->where('id', '!=', $task->id)
        ->latest('id')
        ->first();

    expect($new)->not->toBeNull();
    $ctx = json_decode((string) $new->context, true);
    expect($ctx['review_scope'])->toBe('full')
        ->and($ctx['incremental_base_sha'])->toBeNull();
});

it('redirects to the existing in-flight task when a review for the same head SHA is already queued', function () {
    $task = makeReviewTask();
    fakeOpenPr();

    $existing = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
        'external_id' => 'https://github.com/geocodio/api/pull/77',
        'status' => 'pending',
        'context' => json_encode(['pr_number' => 77, 'head_sha' => 'new-head-sha']),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertRedirect(route('tasks.show', $existing))
        ->assertSessionHas('reReview', 'in_progress');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(2);
});

it('bails to the original task page for closed PRs', function () {
    $task = makeReviewTask();
    fakeOpenPr(['state' => 'closed']);

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('reReview', 'not_open');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

it('bails to the original task page for draft PRs', function () {
    $task = makeReviewTask();
    fakeOpenPr(['draft' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('reReview', 'not_open');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

it('404s on non-review tasks', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'pr_url' => 'https://github.com/geocodio/api/pull/1',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertNotFound();
});

it('404s when the repo has pr review disabled', function () {
    $task = makeReviewTask(['repo' => ['pr_review_enabled' => false]]);

    $this->actingAs(User::factory()->create())
        ->post(route('tasks.re-request-review', $task))
        ->assertNotFound();
});
