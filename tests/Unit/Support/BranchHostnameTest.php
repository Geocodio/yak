<?php

use App\Support\BranchHostname;

it('lowercases and replaces non-alphanumerics with hyphens', function () {
    expect(BranchHostname::sanitize('Feat/Tailwind_V4-Upgrade'))
        ->toBe('feat-tailwind-v4-upgrade');
});

it('collapses repeat hyphens and trims edges', function () {
    expect(BranchHostname::sanitize('---foo///bar---'))->toBe('foo-bar');
});

it('builds the full hostname from repo and branch', function () {
    expect(BranchHostname::build('example-repo', 'feat/tailwind-v4-upgrade', 'yak.example.com'))
        ->toBe('example-repo-feat-tailwind-v4-upgrade.yak.example.com');
});

it('truncates and appends a stable hash when a label exceeds 63 chars', function () {
    $longBranch = str_repeat('a', 80);
    $host = BranchHostname::build('example-repo', $longBranch, 'yak.example.com');

    [$label] = explode('.', $host, 2);
    expect(strlen($label))->toBeLessThanOrEqual(63);
    // Stable across calls with the same inputs
    expect($host)->toBe(BranchHostname::build('example-repo', $longBranch, 'yak.example.com'));
});

it('produces a deterministic collision-suffixed variant', function () {
    $base = BranchHostname::build('example-repo', 'feat/x', 'yak.example.com');
    $suffixed = BranchHostname::withCollisionSuffix('example-repo', 'feat/x', 'yak.example.com');

    [$baseLabel] = explode('.', $base);
    [$suffixedLabel] = explode('.', $suffixed);
    expect($suffixedLabel)->toStartWith($baseLabel . '-');
    expect(strlen(substr($suffixedLabel, strlen($baseLabel) + 1)))->toBe(4);
});
