<?php

use App\DataTransferObjects\TemplateSnapshotRef;

it('formats a snapshot name', function () {
    $ref = new TemplateSnapshotRef('example-repo', 5);
    expect($ref->name())->toBe('yak-tpl-example-repo/ready-v5');
});

it('parses a snapshot name', function () {
    $ref = TemplateSnapshotRef::parse('yak-tpl-example-repo/ready-v7');
    expect($ref->repoSlug)->toBe('example-repo');
    expect($ref->version)->toBe(7);
});

it('returns null on malformed names', function () {
    expect(TemplateSnapshotRef::parse('yak-tpl-foo/ready'))->toBeNull();
    expect(TemplateSnapshotRef::parse('random-string'))->toBeNull();
    expect(TemplateSnapshotRef::parse('yak-tpl-foo/ready-vABC'))->toBeNull();
});

it('parses names with slashes in repo slug', function () {
    // Repository->slug is {owner}/{repo} format in this project
    $ref = TemplateSnapshotRef::parse('yak-tpl-example-org/example-repo/ready-v3');
    // Note: this is a known-ambiguous case because of the slash in slugs.
    // We accept it as null if the parser can't disambiguate; test it actually
    // parses the LAST slash as separator.
    expect($ref)->not->toBeNull();
    expect($ref->repoSlug)->toBe('example-org/example-repo');
    expect($ref->version)->toBe(3);
});
