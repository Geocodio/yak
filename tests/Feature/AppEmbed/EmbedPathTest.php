<?php

use App\Support\AppEmbedRoutes;

it('accepts only a path whose embed page route exists', function (string $path, bool $isEmbedPath) {
    expect(AppEmbedRoutes::isEmbedPath($path))->toBe($isEmbedPath);
})->with([
    'thread' => ['/app/embed/threads/d0e515ef', true],
    'deployment without a route' => ['/app/embed/deployments/42', false],
    'pr review without a route' => ['/app/embed/pr-reviews/example/web.app/17', false],
    'dot segments' => ['/app/embed/pr-reviews/../../123', false],
    'single dot segment' => ['/app/embed/pr-reviews/./x/1', false],
    'trailing dot segment' => ['/app/embed/threads/..', false],
    'parent of the thread' => ['/app/embed/threads/d0e515ef/..', false],
    'another page' => ['/threads/d0e515ef', false],
    'protocol relative' => ['//evil.test/app/embed/threads/d0e515ef', false],
]);
