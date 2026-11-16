<?php

use App\Models\Thread;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $this->thread = Thread::factory()->create();
    $this->base = "/internal/session-store/{$this->thread->id}";
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
    $this->rel = $this->sid . '.jsonl';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

function appendOverHttp(string $base, Thread $thread, string $sid, string $rel, array $chunks): string
{
    $existing = '';
    foreach ($chunks as $chunk) {
        $offset = strlen($existing);
        test()->withToken($thread->sessionStoreToken())->postJson("{$base}/append", [
            'session_id' => $sid,
            'rel_path' => $rel,
            'offset' => $offset,
            'chunk' => base64_encode($chunk),
            'chunk_sha256' => hash('sha256', $chunk),
            'tail_sha256' => hash('sha256', substr($existing, max(0, $offset - 256), min(256, $offset))),
        ])->assertOk();
        $existing .= $chunk;
    }

    return $existing;
}

it('lists the files of a session', function () {
    appendOverHttp($this->base, $this->thread, $this->sid, $this->rel, ["a\n"]);
    $this->withToken($this->thread->sessionStoreToken())->putJson("{$this->base}/file", [
        'session_id' => $this->sid, 'rel_path' => "{$this->sid}/subagents/x.meta.json", 'content' => base64_encode('{}'),
    ])->assertOk();

    $this->withToken($this->thread->sessionStoreToken())
        ->getJson("{$this->base}/files?session_id={$this->sid}")
        ->assertOk()
        ->assertJsonCount(2, 'files')
        ->assertJsonPath('files.0.rel_path', $this->rel)
        ->assertJsonPath('files.0.mode', 'append')
        ->assertJsonPath('files.0.length', 2)
        ->assertJsonPath('files.1.mode', 'whole');
});

it('reads a file back byte for byte, including multi-byte UTF-8 split across segments', function () {
    $all = appendOverHttp($this->base, $this->thread, $this->sid, $this->rel, [
        json_encode(['uuid' => 'u1', 'text' => 'blåbærgrød æøå 日本語']) . "\n",
        json_encode(['uuid' => 'u2', 'text' => 'ünïcödé']) . "\n",
    ]);

    $response = $this->withToken($this->thread->sessionStoreToken())
        ->get("{$this->base}/read?session_id={$this->sid}&rel_path={$this->rel}")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Length', (string) strlen($all));

    expect($response->getContent())->toBe($all);

    $middle = intdiv(strlen($all), 2);
    $split = $this->withToken($this->thread->sessionStoreToken())
        ->get("{$this->base}/read?session_id={$this->sid}&rel_path={$this->rel}&offset={$middle}")
        ->assertOk();
    expect($split->getContent())->toBe(substr($all, $middle));
});

it('reads from an offset', function () {
    $all = appendOverHttp($this->base, $this->thread, $this->sid, $this->rel, ["one\n", "two\n", "three\n"]);

    $response = $this->withToken($this->thread->sessionStoreToken())
        ->get("{$this->base}/read?session_id={$this->sid}&rel_path={$this->rel}&offset=4")
        ->assertOk()
        ->assertHeader('Content-Length', (string) (strlen($all) - 4));

    expect($response->getContent())->toBe("two\nthree\n");
});

it('answers 404 for a missing file', function () {
    $this->withToken($this->thread->sessionStoreToken())
        ->get("{$this->base}/read?session_id={$this->sid}&rel_path={$this->rel}")
        ->assertNotFound();
});

it('returns the tree with parent links', function () {
    appendOverHttp($this->base, $this->thread, $this->sid, $this->rel, [
        json_encode(['uuid' => 'u1']) . "\n",
        json_encode(['uuid' => 'u2', 'parentUuid' => 'u1']) . "\n",
    ]);

    $this->withToken($this->thread->sessionStoreToken())
        ->getJson("{$this->base}/tree?session_id={$this->sid}")
        ->assertOk()
        ->assertJsonCount(2, 'entries')
        ->assertJsonPath('entries.0.uuid', 'u1')
        ->assertJsonPath('entries.0.parent_uuid', null)
        ->assertJsonPath('entries.1.parent_uuid', 'u1')
        ->assertJsonPath('entries.1.rel_path', $this->rel)
        ->assertJsonPath('entries.1.offset', strlen(json_encode(['uuid' => 'u1']) . "\n"));
});

it('never returns another thread file', function () {
    $other = Thread::factory()->create();
    $otherBase = "/internal/session-store/{$other->id}";
    appendOverHttp($this->base, $this->thread, $this->sid, $this->rel, ["mine\n"]);
    appendOverHttp($otherBase, $other, $this->sid, $this->rel, ["theirs\n"]);

    $read = $this->withToken($this->thread->sessionStoreToken())
        ->get("{$this->base}/read?session_id={$this->sid}&rel_path={$this->rel}")->assertOk();
    expect($read->getContent())->toBe("mine\n");

    $this->withToken($this->thread->sessionStoreToken())
        ->getJson("{$this->base}/files?session_id={$this->sid}")
        ->assertJsonCount(1, 'files')->assertJsonPath('files.0.length', 5);
});
