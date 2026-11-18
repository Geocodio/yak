<?php

use App\Http\Resources\EarlyWarningData;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function grantAccess(Repository $repository, User $user, string $access = 'write'): void
{
    Cache::put("co-owner:write-access:{$repository->id}:{$user->github_id}", $access, now()->addMinutes(10));
}

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester']);
    $this->task = YakTask::factory()->create([
        'repo' => 'o-r',
        'started_by_user_id' => $this->requester->id,
        'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown'],
    ]);
});

function writeAccessCrowd(object $test): User
{
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    $reader = User::factory()->withGitHub(2, 'b')->create(['name' => 'Rex Reader']);
    User::factory()->create(['name' => 'Unlinked Una']);
    grantAccess($test->repository, $writer);
    grantAccess($test->repository, $reader, 'read');

    return $writer;
}

it('tags a co-owner with write access', function () {
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    grantAccess($this->repository, $writer);

    $this->actingAs($this->requester)->post(route('threads.co-owner.tag', $this->task->thread), ['user_id' => $writer->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->task->fresh())->tagged_co_owner_user_id->toBe($writer->id)->early_warning->toMatchArray(['state' => 'tagged']);
});

it('refuses a tag without write access and from a non-requester', function () {
    $reader = User::factory()->withGitHub(2, 'b')->create();
    grantAccess($this->repository, $reader, 'read');

    $this->actingAs($this->requester)->post(route('threads.co-owner.tag', $this->task->thread), ['user_id' => $reader->id])
        ->assertSessionHasErrors('earlyWarning');
    expect(session('errors')->get('earlyWarning')[0]['reason'])->toBe('no_write_access');

    $writer = User::factory()->withGitHub(1, 'a')->create();
    grantAccess($this->repository, $writer);
    $this->actingAs($writer)->post(route('threads.co-owner.tag', $this->task->thread), ['user_id' => $writer->id])
        ->assertSessionHasErrors('earlyWarning');
    expect(session('errors')->get('earlyWarning')[0]['reason'])->toBe('not_requester');
    expect($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('declines for the requester only', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post(route('threads.co-owner.decline', $this->task->thread))->assertSessionHasErrors('earlyWarning');
    expect($this->task->fresh()->early_warning['state'])->toBe('shown');

    $this->actingAs($this->requester)->post(route('threads.co-owner.decline', $this->task->thread))->assertSessionHasNoErrors();
    expect($this->task->fresh()->early_warning['state'])->toBe('declined');
});

it('returns no candidates once the notice is no longer pickable', function () {
    $this->task->update(['early_warning' => [...$this->task->early_warning, 'state' => 'declined']]);
    $writer = User::factory()->withGitHub(1, 'a')->create();
    grantAccess($this->repository, $writer);

    $this->actingAs($this->requester);
    expect(EarlyWarningData::candidates($this->task, $this->requester))->toBe([]);
});

it('rewrites the Slack message after a dashboard pick or decline', function () {
    $this->task->update(['source' => 'slack', 'early_warning' => [...$this->task->early_warning, 'slackChannel' => 'C1', 'slackTs' => '1.2']]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    Http::fake(['slack.com/api/chat.update' => Http::response(['ok' => true])]);
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    grantAccess($this->repository, $writer);

    $this->actingAs($this->requester)->post(route('threads.co-owner.tag', $this->task->thread), ['user_id' => $writer->id])->assertSessionHasNoErrors();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'chat.update') && $request['ts'] === '1.2'
        && ! str_contains(json_encode($request['blocks']), 'users_select'));
});

it('names the picked person when their access cannot be checked', function () {
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    $this->actingAs($this->requester)->post(route('threads.co-owner.tag', $this->task->thread), ['user_id' => $writer->id])
        ->assertSessionHasErrors('earlyWarning');
    expect(session('errors')->get('earlyWarning')[0])->toBe([
        'reason' => 'access_check_failed',
        'message' => "Yak could not check Wanda Writer's access on GitHub. Try again in a minute.",
    ]);
});
