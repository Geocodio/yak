<?php

use App\Channels\GitHub\AppService;
use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    GitHubInstallationToken::create([
        'installation_id' => 42,
        'token' => 'ghs_fake_token',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        '*api.github.com/repos/*/deployments/*/statuses' => Http::response(['id' => 111], 201),
        '*api.github.com/repos/*/deployments/*' => Http::response('', 204),
        '*api.github.com/repos/*/deployments' => Http::response(['id' => 987654], 201),
    ]);
});

it('creates a GitHub deployment', function () {
    $id = app(AppService::class)->createDeployment(
        installationId: 42,
        repoSlug: 'example-org/example-repo',
        ref: 'abc1234',
        environment: 'preview/feat-x',
        description: 'Yak preview',
    );

    expect($id)->toBe(987654);
    Http::assertSent(fn ($req) => str_contains($req->url(), '/repos/example-org/example-repo/deployments')
        && $req['environment'] === 'preview/feat-x'
        && $req['ref'] === 'abc1234'
        && $req['transient_environment'] === true
        && $req['required_contexts'] === []
    );
});

it('creates a deployment status', function () {
    app(AppService::class)->createDeploymentStatus(
        installationId: 42,
        repoSlug: 'example-org/example-repo',
        deploymentId: 987654,
        state: 'success',
        environmentUrl: 'https://x.yak.example.com',
        logUrl: 'https://yak.example.com/deployments/42',
        description: 'preview up',
    );

    Http::assertSent(fn ($req) => str_contains($req->url(), '/deployments/987654/statuses')
        && $req['state'] === 'success'
        && $req['environment_url'] === 'https://x.yak.example.com'
    );
});

it('deletes a GitHub deployment', function () {
    app(AppService::class)->deleteDeployment(42, 'example-org/example-repo', 987654);

    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && str_contains($req->url(), '/deployments/987654')
    );
});
