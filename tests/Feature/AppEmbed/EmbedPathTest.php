<?php

use App\Support\AppEmbedRoutes;

it('accepts only a path whose embed page route exists', function (string $path, bool $isEmbedPath) {
    expect(AppEmbedRoutes::isEmbedPath($path))->toBe($isEmbedPath);
})->with([
    'thread' => ['/app/embed/threads/d0e515ef', true],
    'deployments list' => ['/app/embed/deployments', true],
    'deployment' => ['/app/embed/deployments/abcd1234', true],
    'deployment by a legacy numeric id' => ['/app/embed/deployments/42', false],
    'deployments list with a slash' => ['/app/embed/deployments/', false],
    'pr reviews list' => ['/app/embed/pr-reviews', true],
    'pr review' => ['/app/embed/pr-reviews/example/web.app/17', true],
    'pr review with a one-segment slug' => ['/app/embed/pr-reviews/my-repo/17', true],
    'pr review with three segments' => ['/app/embed/pr-reviews/a/b/c/17', false],
    'pr review with an empty segment' => ['/app/embed/pr-reviews/my-repo//17', false],
    'pr review with a dot segment slug' => ['/app/embed/pr-reviews/a/../17', false],
    'pr review with a trailing slash' => ['/app/embed/pr-reviews/my-repo/17/', false],
    'pr review with an encoded slash' => ['/app/embed/pr-reviews/a%2Fb/17', false],
    'pr review without a number' => ['/app/embed/pr-reviews/example/web.app', false],
    'pr review with the web path' => ['/app/embed/pr-reviews/for/example/web.app/17', false],
    'dot segments' => ['/app/embed/pr-reviews/../../123', false],
    'single dot segment' => ['/app/embed/pr-reviews/./x/1', false],
    'trailing dot segment' => ['/app/embed/threads/..', false],
    'parent of the thread' => ['/app/embed/threads/d0e515ef/..', false],
    'another page' => ['/threads/d0e515ef', false],
    'protocol relative' => ['//evil.test/app/embed/threads/d0e515ef', false],
]);
