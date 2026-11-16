<?php

use App\Models\BranchDeployment;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->hosts = app(ThreadPreviewHosts::class);
});

it('assigns a stable t- prefixed host under the suffix', function () {
    $thread = Thread::factory()->create();

    $host = $this->hosts->hostnameFor($thread);

    expect($host)->toMatch('/^t-[a-z0-9]{10}\.yak\.example\.com$/')
        ->and($this->hosts->hostnameFor($thread))->toBe($host)
        ->and($this->hosts->hostnameFor(Thread::withoutGlobalScopes()->find($thread->id)))->toBe($host)
        ->and($this->hosts->isThreadHost($host))->toBeTrue()
        ->and($this->hosts->isThreadHost('foo.yak.example.com'))->toBeFalse();
});

it('never reuses a branch deployment hostname', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 't-aaaaaaaaaa.yak.example.com']);
    Str::createRandomStringsUsingSequence(['AAAAAAAAAA', 'bbbbbbbbbb']);

    $host = $this->hosts->hostnameFor(Thread::factory()->create());
    Str::createRandomStringsNormally();

    expect($host)->toBe('t-bbbbbbbbbb.yak.example.com');
});

it('resolves a host to its thread case-insensitively', function () {
    $thread = Thread::factory()->create();
    $host = $this->hosts->hostnameFor($thread);

    expect($this->hosts->threadForHost(strtoupper($host))?->id)->toBe($thread->id);
});

it('returns null for a branch deployment host and for an unknown host', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    expect($this->hosts->threadForHost('foo.yak.example.com'))->toBeNull()
        ->and($this->hosts->threadForHost('t-nope000000.yak.example.com'))->toBeNull();
});

it('builds an https url with the path', function () {
    $thread = Thread::factory()->create();
    $host = $this->hosts->hostnameFor($thread);

    expect($this->hosts->urlFor($thread))->toBe("https://{$host}/")
        ->and($this->hosts->urlFor($thread, '/a?b=1'))->toBe("https://{$host}/a?b=1");
});
