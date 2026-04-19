<?php

use App\Agents\SandboxedAgentRunner;
use App\Contracts\AgentRunner;
use App\Services\IncusSandboxManager;
use App\Services\VideoRenderer;

/**
 * Guard against BindingResolutionException at runtime for services with
 * primitive constructor params that Laravel's container cannot auto-resolve.
 *
 * If AppServiceProvider forgets to bind one, every other test that mocks
 * it via `$this->mock(...)` still passes — the mock short-circuits the
 * container. Only a direct `app(...)` call exercises the real binding.
 */
it('can resolve VideoRenderer through the container', function () {
    expect(app(VideoRenderer::class))->toBeInstanceOf(VideoRenderer::class);
});

it('can resolve AgentRunner through the container', function () {
    expect(app(AgentRunner::class))->toBeInstanceOf(SandboxedAgentRunner::class);
});

it('can resolve IncusSandboxManager through the container', function () {
    expect(app(IncusSandboxManager::class))->toBeInstanceOf(IncusSandboxManager::class);
});
