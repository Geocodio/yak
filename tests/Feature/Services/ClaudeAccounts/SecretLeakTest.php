<?php

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountNotifier;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

const LEAKED_SECRET = 'sk-ant-oat01-leakcheckvalue1234567890abcdef';

beforeEach(function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->andReturn('yakph-placeholder');
    $placeholders->shouldReceive('revoke');
    app()->instance(AuthProxyPlaceholders::class, $placeholders);

    $this->user = User::factory()->create(['slack_user_id' => 'U1']);
    $this->actingAs($this->user);
});

it('keeps a pasted secret out of the response, session, logs and notifications when validation fails', function (string $output) {
    Log::spy();
    Process::fake(['*' => Process::result(output: $output, errorOutput: "boom {$output}", exitCode: 1)]);

    $response = $this->from(route('settings.claude-accounts'))->post(route('settings.claude-accounts.store'), [
        'kind' => 'setup_token',
        'secret' => LEAKED_SECRET,
        'name' => 'Work',
    ]);

    $response->assertSessionHasErrors('secret');
    expect($response->getContent())->not->toContain(LEAKED_SECRET)
        ->and(json_encode(session()->all()))->not->toContain(LEAKED_SECRET)
        ->and(json_encode(session('errors')?->getBag('default')->toArray()))->not->toContain(LEAKED_SECRET);

    Log::shouldNotHaveReceived('log');
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::shouldNotHaveReceived($level, fn (...$arguments) => str_contains(json_encode($arguments), LEAKED_SECRET));
    }

    expect(ClaudeAccount::query()->count())->toBe(0);
})->with([
    'plain output' => fn () => 'Invalid bearer token ' . LEAKED_SECRET,
    'json result' => fn () => json_encode(['is_error' => true, 'result' => 'authentication failed for ' . LEAKED_SECRET]),
    'unauthorized' => fn () => '401 unauthorized ' . LEAKED_SECRET,
]);

it('redacts the secret when the process throws', function () {
    Log::spy();
    Process::fake(['*' => fn () => throw new RuntimeException('failed with ' . LEAKED_SECRET)]);

    $response = $this->post(route('settings.claude-accounts.store'), ['kind' => 'setup_token', 'secret' => LEAKED_SECRET, 'name' => 'Work']);

    $response->assertSessionHasErrors('secret');
    expect($response->getContent())->not->toContain(LEAKED_SECRET)
        ->and(json_encode(session('errors')?->getBag('default')->toArray()))->not->toContain(LEAKED_SECRET);
    Log::shouldNotHaveReceived('error', fn (...$arguments) => str_contains(json_encode($arguments), LEAKED_SECRET));
});

it('never puts the stored secret into a notification', function () {
    $account = ClaudeAccount::factory()->for($this->user, 'owner')->invalid()->create(['secret' => LEAKED_SECRET, 'last_error' => 'Rejected ' . LEAKED_SECRET]);

    app(AccountNotifier::class)->invalid($account);
    app(AccountNotifier::class)->expired($account);

    $payloads = Http::recorded()->map(fn ($pair) => json_encode($pair[0]->data()))->implode(' ');
    expect($payloads)->not->toBe('')->not->toContain(LEAKED_SECRET);
});
