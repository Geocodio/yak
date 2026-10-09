<?php

beforeEach(function () {
    $this->manifest = file_get_contents(__DIR__ . '/../../ansible/roles/github-app/templates/app-manifest.json.j2');
});

it('requests write access to checks and read access to members', function () {
    expect($this->manifest)
        ->toContain('"checks": "write"')
        ->toContain('"members": "read"');
});

it('subscribes to push, delete and repository events', function () {
    expect($this->manifest)
        ->toContain('"push"')
        ->toContain('"delete"')
        ->toContain('"repository"');
});
