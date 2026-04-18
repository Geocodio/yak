<?php

use App\Support\GitHubDiffLines;

it('returns context and added lines from the RIGHT side of the patch', function () {
    $patch = <<<'PATCH'
@@ -10,5 +10,7 @@
 existing line 10
-removed line
+added line 11
 context line 12
+added line 13
 context line 14
PATCH;

    $map = GitHubDiffLines::buildMap([[
        'filename' => 'app/Foo.php',
        'patch' => $patch,
    ]]);

    expect($map)->toHaveKey('app/Foo.php')
        ->and(array_keys($map['app/Foo.php']))->toEqualCanonicalizing([10, 11, 12, 13, 14]);
});

it('handles multiple hunks in the same file', function () {
    $patch = <<<'PATCH'
@@ -1,2 +1,3 @@
 a
+b
 c
@@ -50,2 +51,3 @@
 x
+y
 z
PATCH;

    $map = GitHubDiffLines::buildMap([[
        'filename' => 'src/x.ts',
        'patch' => $patch,
    ]]);

    expect(array_keys($map['src/x.ts']))->toEqualCanonicalizing([1, 2, 3, 51, 52, 53]);
});

it('ignores files with empty patches (binary, renamed-only, etc.)', function () {
    $map = GitHubDiffLines::buildMap([
        ['filename' => 'logo.png', 'patch' => ''],
        ['filename' => 'empty.txt'],
    ]);

    expect($map)->toBeEmpty();
});

it('skips -deleted lines since they have no RIGHT-side line number', function () {
    $patch = <<<'PATCH'
@@ -1,3 +1,1 @@
-deleted one
-deleted two
 kept
PATCH;

    $map = GitHubDiffLines::buildMap([[
        'filename' => 'x.md',
        'patch' => $patch,
    ]]);

    expect(array_keys($map['x.md']))->toBe([1]);
});
