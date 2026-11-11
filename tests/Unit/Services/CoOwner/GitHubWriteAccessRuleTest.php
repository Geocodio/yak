<?php

use App\Services\CoOwner\GitHubWriteAccess;

it('grants write by role or push flag only', function (?array $permission, bool $expected) {
    expect(GitHubWriteAccess::grantsWrite($permission))->toBe($expected);
})->with([
    'admin' => [['role_name' => 'admin'], true],
    'maintain' => [['role_name' => 'maintain'], true],
    'write' => [['role_name' => 'write'], true],
    'push flag' => [['role_name' => 'triage', 'user' => ['permissions' => ['push' => true]]], true],
    'read' => [['role_name' => 'read', 'user' => ['permissions' => ['push' => false]]], false],
    'triage' => [['role_name' => 'triage'], false],
    'empty' => [[], false],
    'null' => [null, false],
]);
