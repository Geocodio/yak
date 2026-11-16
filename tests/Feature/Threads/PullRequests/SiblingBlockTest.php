<?php

use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\PullRequests\SiblingBlock;
use App\Services\Threads\PullRequests\SiblingBlockPublisher;
use Illuminate\Support\Facades\Http;

function siblingThread(): array
{
    $thread = Thread::factory()->create(['title' => 'Secret launch plan']);
    $api = ThreadRepository::factory()->for($thread)->withPullRequest(4)->create(['repo' => 'acme/api', 'pull_request_title' => 'Rename widget', 'merge_position' => 1]);
    $web = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(9)->create(['repo' => 'acme/web', 'pull_request_title' => 'Use new widget', 'merge_position' => 2]);

    return [$thread, $api, $web];
}

it('renders a numbered order with this pull request marked', function () {
    [$thread, $api, $web] = siblingThread();

    $text = app(SiblingBlock::class)->render($thread, $web);

    expect($text)->toContain('### Related pull requests')
        ->toContain('This change spans 2 repositories. Merge in this order:')
        ->toContain('1. acme/api#4 Rename widget')
        ->toContain('2. acme/web#9 Use new widget (this pull request)')
        ->toContain('Merge after acme/api#4')
        ->toContain('Merge order is a suggestion. Yak does not merge pull requests.');
    expect(substr_count($text, 'Merge after'))->toBe(1);
});

it('omits the block for a single pull request', function () {
    [$thread, , $web] = siblingThread();
    $web->forceFill(['pull_request_url' => null, 'pull_request_number' => null, 'pull_request_state' => null])->save();

    expect(app(SiblingBlock::class)->render($thread, $web))->toBeNull();
});

it('lists context repos', function () {
    [$thread, $api] = siblingThread();
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/infra']);

    expect(app(SiblingBlock::class)->render($thread, $api))->toContain('Also used as context, with no pull request: acme/docs, acme/infra.');
});

it('shows a merged pull request as merged', function () {
    [$thread, $api, $web] = siblingThread();
    $api->forceFill(['pull_request_state' => 'merged'])->save();

    expect(app(SiblingBlock::class)->render($thread, $web))->toContain('acme/api#4 Rename widget (merged)');
});

it('never names the thread title or the titles of a private thread', function () {
    [$thread, , $web] = siblingThread();
    $thread->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id])->save();

    $text = app(SiblingBlock::class)->render($thread, $web);

    expect($text)->not->toContain('Secret launch plan')->not->toContain('Rename widget')->toContain('acme/api#4');
});

function fakeBodies(array $bodies): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/pulls/4' => Http::response(['body' => $bodies['acme/api#4'] ?? '']),
        'api.github.com/repos/acme/web/pulls/9' => Http::response(['body' => $bodies['acme/web#9'] ?? '']),
    ]);
}

it('updates every open pull request body and appends once when markers are missing', function () {
    config()->set('yak.channels.github.installation_id', 1);
    [$thread] = siblingThread();
    fakeBodies(['acme/api#4' => "Intro\n\n<!-- yak:siblings -->\n\n<!-- /yak:siblings -->", 'acme/web#9' => 'Old body without markers']);

    app(SiblingBlockPublisher::class)->publish($thread);

    $patches = Http::recorded(fn ($request) => $request->method() === 'PATCH');
    expect($patches)->toHaveCount(2);
    $web = $patches->first(fn ($pair) => str_contains($pair[0]->url(), 'acme/web'))[0]->data()['body'];
    expect($web)->toStartWith('Old body without markers')->and(substr_count($web, '<!-- yak:siblings -->'))->toBe(1)->and($web)->toContain('(this pull request)');
    $api = $patches->first(fn ($pair) => str_contains($pair[0]->url(), 'acme/api'))[0]->data()['body'];
    expect($api)->toStartWith('Intro')->toContain('1. acme/api#4 Rename widget (this pull request)');
});

it('does not rewrite a merged pull request', function () {
    config()->set('yak.channels.github.installation_id', 1);
    [$thread, $api] = siblingThread();
    $api->forceFill(['pull_request_state' => 'merged'])->save();
    fakeBodies([]);

    app(SiblingBlockPublisher::class)->publish($thread);

    expect(Http::recorded(fn ($request) => $request->method() === 'PATCH'))->toHaveCount(1);
});
