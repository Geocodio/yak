<?php

use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver;
use App\Channels\Contracts\NotificationDriver;
use App\Channels\Linear\WebhookController as LinearWebhookController;
use App\Channels\Sentry\WebhookController as SentryWebhookController;
use App\DataTransferObjects\BuildResult;
use App\DataTransferObjects\TaskDescription;
use App\Enums\NotificationType;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\Webhooks\SlackWebhookController;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
|--------------------------------------------------------------------------
| InputDriver Interface
|--------------------------------------------------------------------------
*/

test('InputDriver interface defines parse method returning TaskDescription', function () {
    $reflection = new ReflectionClass(InputDriver::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('parse'))->toBeTrue();

    $method = $reflection->getMethod('parse');
    expect($method->getNumberOfParameters())->toBe(1);
    expect($method->getParameters()[0]->getType()?->getName())->toBe(Request::class);
    expect($method->getReturnType()?->getName())->toBe(TaskDescription::class);
});

/*
|--------------------------------------------------------------------------
| CIDriver Interface
|--------------------------------------------------------------------------
*/

test('CIDriver interface defines parse method returning BuildResult', function () {
    $reflection = new ReflectionClass(CIDriver::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('parse'))->toBeTrue();

    $method = $reflection->getMethod('parse');
    expect($method->getNumberOfParameters())->toBe(1);
    expect($method->getParameters()[0]->getType()?->getName())->toBe(Request::class);
    expect($method->getReturnType()?->getName())->toBe(BuildResult::class);
});

/*
|--------------------------------------------------------------------------
| NotificationDriver Interface
|--------------------------------------------------------------------------
*/

test('NotificationDriver interface defines send method', function () {
    $reflection = new ReflectionClass(NotificationDriver::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('send'))->toBeTrue();

    $method = $reflection->getMethod('send');
    expect($method->getNumberOfParameters())->toBe(3);
    expect($method->getParameters()[0]->getType()?->getName())->toBe(YakTask::class);
    expect($method->getParameters()[1]->getType()?->getName())->toBe(NotificationType::class);
    expect($method->getParameters()[2]->getType()?->getName())->toBe('string');
    expect($method->getReturnType()?->getName())->toBe('void');
});

/*
|--------------------------------------------------------------------------
| TaskDescription DTO
|--------------------------------------------------------------------------
*/

test('TaskDescription holds normalized task data', function () {
    $dto = new TaskDescription(
        title: 'Fix login bug',
        body: 'Users cannot log in with SSO',
        channel: 'github',
        externalId: 'issue-123',
        repository: 'org/repo',
        metadata: ['labels' => ['bug']],
    );

    expect($dto->title)->toBe('Fix login bug');
    expect($dto->body)->toBe('Users cannot log in with SSO');
    expect($dto->channel)->toBe('github');
    expect($dto->externalId)->toBe('issue-123');
    expect($dto->repository)->toBe('org/repo');
    expect($dto->metadata)->toBe(['labels' => ['bug']]);
});

test('TaskDescription has sensible defaults for optional fields', function () {
    $dto = new TaskDescription(
        title: 'Do something',
        body: 'Details',
        channel: 'slack',
        externalId: 'msg-456',
    );

    expect($dto->repository)->toBeNull();
    expect($dto->metadata)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| BuildResult DTO
|--------------------------------------------------------------------------
*/

test('BuildResult holds pass/fail with failure output', function () {
    $dto = new BuildResult(
        passed: false,
        externalId: 'build-789',
        repository: 'org/repo',
        output: 'Test suite failed: 3 errors',
        commitSha: 'abc123',
        metadata: ['duration' => 120],
    );

    expect($dto->passed)->toBeFalse();
    expect($dto->externalId)->toBe('build-789');
    expect($dto->repository)->toBe('org/repo');
    expect($dto->output)->toBe('Test suite failed: 3 errors');
    expect($dto->commitSha)->toBe('abc123');
    expect($dto->metadata)->toBe(['duration' => 120]);
});

test('BuildResult has sensible defaults for optional fields', function () {
    $dto = new BuildResult(
        passed: true,
        externalId: 'build-100',
        repository: 'org/repo',
    );

    expect($dto->output)->toBeNull();
    expect($dto->commitSha)->toBeNull();
    expect($dto->metadata)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| VerifiesWebhookSignature Trait
|--------------------------------------------------------------------------
*/

test('verifyWebhookSignature passes with valid signature', function () {
    $controller = new GitHubWebhookController;
    $secret = 'test-secret';
    $payload = '{"action":"opened"}';
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    config()->set('yak.channels.github.webhook_secret', $secret);

    $request = Request::create('/webhooks/github', 'POST', content: $payload);
    $request->headers->set('X-Hub-Signature-256', $signature);

    $reflection = new ReflectionMethod($controller, 'verifyWebhookSignature');

    // Should not throw
    $reflection->invoke($controller, $request, $secret, 'X-Hub-Signature-256');
    expect(true)->toBeTrue();
});

test('verifyWebhookSignature rejects invalid signature', function () {
    $controller = new GitHubWebhookController;
    $secret = 'test-secret';
    $payload = '{"action":"opened"}';

    $request = Request::create('/webhooks/github', 'POST', content: $payload);
    $request->headers->set('X-Hub-Signature-256', 'sha256=invalid');

    $reflection = new ReflectionMethod($controller, 'verifyWebhookSignature');

    $reflection->invoke($controller, $request, $secret, 'X-Hub-Signature-256');
})->throws(AccessDeniedHttpException::class, 'Invalid webhook signature.');

test('verifyWebhookSignature rejects missing signature header', function () {
    $controller = new GitHubWebhookController;
    $secret = 'test-secret';
    $payload = '{"action":"opened"}';

    $request = Request::create('/webhooks/github', 'POST', content: $payload);

    $reflection = new ReflectionMethod($controller, 'verifyWebhookSignature');

    $reflection->invoke($controller, $request, $secret, 'X-Hub-Signature-256');
})->throws(AccessDeniedHttpException::class);

test('verifyWebhookSignature supports custom prefix', function () {
    $controller = new SlackWebhookController;
    $secret = 'slack-secret';
    $payload = 'v0:1234567890:body-content';
    $signature = 'v0=' . hash_hmac('sha256', $payload, $secret);

    $request = Request::create('/webhooks/slack', 'POST', content: $payload);
    $request->headers->set('X-Slack-Signature', $signature);

    $reflection = new ReflectionMethod($controller, 'verifyWebhookSignature');

    $reflection->invoke($controller, $request, $secret, 'X-Slack-Signature', 'sha256', 'v0=');
    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Webhook Controllers Use VerifiesWebhookSignature Trait
|--------------------------------------------------------------------------
*/

test('all webhook controllers use VerifiesWebhookSignature trait', function () {
    $controllers = [
        GitHubWebhookController::class,
        SlackWebhookController::class,
        LinearWebhookController::class,
        SentryWebhookController::class,
    ];

    foreach ($controllers as $controller) {
        $traits = class_uses_recursive($controller);
        expect($traits)->toHaveKey(VerifiesWebhookSignature::class);
    }
});

/*
|--------------------------------------------------------------------------
| Conditional Route Registration
|--------------------------------------------------------------------------
*/

test('GitHub webhook route is always registered', function () {
    $this->post('/webhooks/github')->assertStatus(403);
});

test('disabled channel routes return 404', function () {
    // Without credentials, optional channels should not be registered.
    // Drone is excluded: it has no inbound webhook at all (polled instead).
    $optionalChannels = ['slack', 'linear', 'sentry'];

    foreach ($optionalChannels as $channel) {
        $this->post("/webhooks/{$channel}")
            ->assertNotFound("Expected /webhooks/{$channel} to return 404 when disabled");
    }
});

test('drone webhook routes are never registered (polled instead of webhooked)', function () {
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => 'drone-token',
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    $this->post('/webhooks/drone')->assertNotFound();
    $this->post('/webhooks/ci/drone')->assertNotFound();
});

test('enabled channel routes are registered', function () {
    // Slack channel enabled with credentials
    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test',
        'signing_secret' => 'test-secret',
    ]);

    // Re-register routes by booting the provider
    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    // Route should now be registered and respond (403 because no valid signature)
    $this->post('/webhooks/slack')->assertStatus(403);
});
