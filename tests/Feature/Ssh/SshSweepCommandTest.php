<?php

use App\Models\GithubKeySync;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

function syncFor(string $status, int $secondsAgo, int $githubId): User
{
    $user = User::factory()->create(['github_id' => $githubId]);
    GithubKeySync::factory()->forUser($user)->create(['status' => $status, 'checked_at' => now()->subSeconds($secondsAgo)]);

    return $user;
}

it('refreshes only rows past their ttl', function () {
    Http::swap(new Factory);
    Http::fake(['api.github.com/user/*' => Http::response(['login' => 'someone']), 'github.com/*' => Http::response('')]);

    syncFor('ok', 60, 1);
    syncFor('ok', 400, 2);
    syncFor('error', 400, 3);
    syncFor('empty', 600, 4);
    syncFor('empty', 1000, 5);
    syncFor('unknown_account', 1000, 6);

    $this->artisan('yak:ssh-sweep')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/2');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/3');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/5');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/6');
    Http::assertNotSent(fn ($request) => in_array($request->url(), ['https://api.github.com/user/1', 'https://api.github.com/user/4'], true));
});

it('refreshes at most fifty rows per run', function () {
    Http::swap(new Factory);
    Http::fake(['api.github.com/user/*' => Http::response([], 404)]);

    foreach (range(1, 55) as $id) {
        syncFor('ok', 1000, $id);
    }

    $this->artisan('yak:ssh-sweep')->assertSuccessful();

    Http::assertSentCount(50);
});

it('is scheduled hourly', function () {
    $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command, 'yak:ssh-sweep'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});
