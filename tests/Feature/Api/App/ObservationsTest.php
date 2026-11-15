<?php

use App\Models\Observation;
use App\Models\User;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\ContractFixture;

function observationsWorld(): User
{
    test()->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['app.url' => 'https://yak.example.com']);
    URL::forceRootUrl('https://yak.example.com');
    URL::forceScheme('https');

    return User::factory()->create(['name' => 'Test person']);
}

it('lists observations with public task ids', function () {
    $user = observationsWorld();
    $task = YakTask::factory()->create(['public_id' => 'k7m2q9rt', 'started_by_user_id' => $user->id, 'thread_id' => null]);
    Observation::factory()->create([
        'repo' => 'example/api', 'summary' => 'A test in the upload suite fails one run in ten.', 'subject' => 'UploadRetryTest',
        'yak_task_id' => $task->id, 'created_at' => now()->subHour(),
    ]);
    Observation::factory()->declined()->create([
        'repo' => 'example/api', 'summary' => 'The failing test already has an open pull request.', 'subject' => 'UploadRetryTest',
        'reference_url' => 'https://github.com/example/api/pull/41', 'created_at' => now()->subMinutes(5),
    ]);

    $response = $this->getJson('/api/app/v1/observations', phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(2, 'observations')
        ->assertJsonPath('observations.0.taskId', null)
        ->assertJsonPath('observations.0.outcome', 'declined')
        ->assertJsonPath('observations.1.taskId', 'k7m2q9rt')
        ->assertJsonPath('observations.1.taskUrl', 'https://yak.example.com/tasks/k7m2q9rt')
        ->assertJsonPath('nextPage', null)
        ->assertJsonPath('options.repos', ['example/api']);
    ContractFixture::assertMatches('app', 'observations', $response->json(), ['observations.*.id']);
});

it('answers an empty list', function () {
    $user = observationsWorld();

    $response = $this->getJson('/api/app/v1/observations', phoneHeadersFor($user))->assertOk()
        ->assertExactJson(['observations' => [], 'nextPage' => null, 'options' => ['repos' => []]]);
    ContractFixture::assertMatches('app', 'observations-empty', $response->json());
});

it('hides the task of a private thread', function () {
    $user = observationsWorld();
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $owner->id]);
    Observation::factory()->create(['summary' => 'Hidden link', 'yak_task_id' => $task->id]);

    $this->getJson('/api/app/v1/observations', phoneHeadersFor($user))->assertOk()
        ->assertJsonCount(1, 'observations')
        ->assertJsonPath('observations.0.summary', 'Hidden link')
        ->assertJsonPath('observations.0.taskId', null)
        ->assertJsonPath('observations.0.taskUrl', null);

    $this->getJson('/api/app/v1/observations', phoneHeadersFor($owner))->assertOk()
        ->assertJsonPath('observations.0.taskId', $task->public_id);
});

it('filters by repository, outcome and search', function () {
    $user = observationsWorld();
    Observation::factory()->create(['repo' => 'example/api', 'summary' => 'Upload test is flaky', 'subject' => 'UploadTest']);
    Observation::factory()->declined()->create(['repo' => 'example/web', 'summary' => 'Checkout is slow', 'subject' => 'CheckoutTest']);
    $headers = phoneHeadersFor($user);

    $this->getJson('/api/app/v1/observations?repo=example/web', $headers)->assertOk()
        ->assertJsonCount(1, 'observations')->assertJsonPath('observations.0.repo', 'example/web');
    $this->getJson('/api/app/v1/observations?outcome=acted', $headers)->assertOk()
        ->assertJsonCount(1, 'observations')->assertJsonPath('observations.0.repo', 'example/api');
    $this->getJson('/api/app/v1/observations?search=UPLOAD', $headers)->assertOk()
        ->assertJsonCount(1, 'observations')->assertJsonPath('observations.0.repo', 'example/api');
    $this->getJson('/api/app/v1/observations?search=checkouttest', $headers)->assertOk()
        ->assertJsonCount(1, 'observations')->assertJsonPath('observations.0.repo', 'example/web');
    $this->getJson('/api/app/v1/observations?search=' . urlencode('100%_'), $headers)->assertOk()
        ->assertJsonCount(0, 'observations');
});

it('validates the query', function (string $query, string $field) {
    $user = observationsWorld();

    $this->getJson('/api/app/v1/observations?' . $query, phoneHeadersFor($user))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    'outcome' => ['outcome=maybe', 'outcome'],
    'page' => ['page=0', 'page'],
    'repo' => ['repo=' . str_repeat('a', 256), 'repo'],
    'search' => ['search=' . str_repeat('a', 101), 'search'],
]);

it('pages by 50', function () {
    $user = observationsWorld();
    Observation::factory()->count(51)->sequence(fn ($sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create();
    $headers = phoneHeadersFor($user);

    $this->getJson('/api/app/v1/observations', $headers)->assertOk()
        ->assertJsonCount(50, 'observations')->assertJsonPath('nextPage', 2);
    $this->getJson('/api/app/v1/observations?page=2', $headers)->assertOk()
        ->assertJsonCount(1, 'observations')->assertJsonPath('nextPage', null);
});

it('runs one task query per page', function () {
    $user = observationsWorld();
    Observation::factory()->count(50)->create()->each(
        fn (Observation $observation) => $observation->update(['yak_task_id' => YakTask::factory()->create(['thread_id' => null])->id]),
    );
    $headers = phoneHeadersFor($user);

    DB::enableQueryLog();
    $this->getJson('/api/app/v1/observations', $headers)->assertOk()->assertJsonCount(50, 'observations');
    $taskSelects = collect(DB::getQueryLog())->filter(fn (array $query) => preg_match('/from "tasks"/', $query['query']) === 1);

    expect($taskSelects)->toHaveCount(1);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();

    $this->getJson('/api/app/v1/observations', ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson('/api/app/v1/observations', $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson('/api/app/v1/observations', $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
