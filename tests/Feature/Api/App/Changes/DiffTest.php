<?php

use App\Enums\DiffFileStatus;
use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadDiffRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

const DIFF_PATCH = "@@ -1,4 +1,6 @@\n function retry()\n {\n-    return false;\n+    \$attempts = 3;\n+    return true;\n }\n@@ -20,3 +22,7 @@ class Uploader\n     public function run()\n     {\n-        \$this->send();\n+        \$this->sendWithRetry();\n+        \$this->log();\n+        \$this->flush();\n+        \$this->done();\n     }\n";

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = Thread::factory()->create([
        'public_id' => 'd0e515ef',
        'title' => 'Retry failed uploads',
        'repo' => 'example/api',
        'created_by_user_id' => $this->owner->id,
        'owner_user_id' => $this->owner->id,
    ]);
    $this->diff = ThreadDiff::factory()->create([
        'thread_id' => $this->thread->id,
        'base_sha' => '3f2a9c1e0b7d4a6f8e5c2b1a0d9f8e7c6b5a4d3c',
        'head_sha' => '9b8a7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b',
        'is_truncated' => true,
        'captured_at' => now(),
        'files_count' => 3,
        'additions' => 9,
        'deletions' => 4,
    ]);
    $this->modified = ThreadDiffFile::factory()->create(['thread_diff_id' => $this->diff->id, 'path' => 'app/Uploads/RetryUpload.php', 'additions' => 9, 'deletions' => 4, 'patch' => DIFF_PATCH]);
    $this->binary = ThreadDiffFile::factory()->binary()->create(['thread_diff_id' => $this->diff->id, 'path' => 'resources/logo.png', 'status' => DiffFileStatus::Added]);
    $this->tooLarge = ThreadDiffFile::factory()->tooLarge()->create(['thread_diff_id' => $this->diff->id, 'path' => 'storage/exports/uploads.csv', 'status' => DiffFileStatus::Added, 'additions' => null, 'deletions' => null]);
    $extra = ThreadDiffRepository::factory()->create(['thread_diff_id' => $this->diff->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes, 'base_sha' => str_repeat('c', 40), 'head_sha' => str_repeat('d', 40), 'files_count' => 1, 'additions' => 2, 'deletions' => 0]);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $this->diff->id, 'thread_diff_repository_id' => $extra->id, 'path' => 'src/uploads.ts', 'old_path' => 'src/upload.ts', 'status' => DiffFileStatus::Renamed, 'additions' => 2, 'deletions' => 0]);
});

it('lists the diff of two repositories', function () {
    $response = $this->getJson(appThreadPath($this->thread, '/diff'), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('diff.isTruncated', true)
        ->assertJsonPath('diff.isTurnRunning', false)
        ->assertJsonPath('diff.repositories.0.role', 'primary')
        ->assertJsonPath('diff.repositories.1.repo', 'example/web');

    expect(collect($response->json('diff.files'))->pluck('path')->all())->toContain('app/Uploads/RetryUpload.php', 'resources/logo.png', 'storage/exports/uploads.csv', 'src/uploads.ts');
    ContractFixture::assertMatches('app', 'thread-diff', $response->json(), ['diff.files.*.id']);
});

it('answers a null diff before the first capture', function () {
    $this->diff->delete();

    $response = $this->getJson(appThreadPath($this->thread, '/diff'), phoneHeadersFor($this->owner))->assertOk()->assertExactJson(['diff' => null]);
    ContractFixture::assertMatches('app', 'thread-diff-none', $response->json());
});

it('returns one file with its patch', function () {
    $response = $this->getJson(appThreadPath($this->thread, "/diff/files/{$this->modified->id}"), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('patch', DIFF_PATCH)
        ->assertJsonPath('status', 'modified');
    ContractFixture::assertMatches('app', 'diff-file', $response->json());
});

it('returns a binary file without a patch', function () {
    $response = $this->getJson(appThreadPath($this->thread, "/diff/files/{$this->binary->id}"), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('isBinary', true)
        ->assertJsonPath('patch', null);
    ContractFixture::assertMatches('app', 'diff-file-binary', $response->json());
});

it('returns a too large file without a patch', function () {
    $response = $this->getJson(appThreadPath($this->thread, "/diff/files/{$this->tooLarge->id}"), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('isTooLarge', true)
        ->assertJsonPath('patch', null);
    ContractFixture::assertMatches('app', 'diff-file-too-large', $response->json());
});

it('answers 404 for a file of another thread, a replaced file and a huge id', function () {
    $headers = phoneHeadersFor($this->owner);
    $otherThread = Thread::factory()->create(['created_by_user_id' => $this->owner->id, 'owner_user_id' => $this->owner->id]);
    $otherFile = ThreadDiffFile::factory()->create(['thread_diff_id' => ThreadDiff::factory()->create(['thread_id' => $otherThread->id])->id]);
    $replacedId = $this->modified->id;
    $this->diff->delete();
    ThreadDiff::factory()->create(['thread_id' => $this->thread->id]);

    $bodies = collect([(string) $otherFile->id, (string) $replacedId, '99999999999', '00000000000000000001'])
        ->map(fn (string $id): array => $this->getJson(appThreadPath($this->thread, "/diff/files/{$id}"), $headers)->assertNotFound()->assertJsonPath('error', 'not_found')->json());

    expect($bodies->unique()->count())->toBe(1);
});

it('lets a share recipient read the diff', function () {
    $recipient = shareThreadWith($this->thread, $this->owner);

    $this->getJson(appThreadPath($this->thread, '/diff'), phoneHeadersFor($recipient))->assertOk();
    $this->getJson(appThreadPath($this->thread, "/diff/files/{$this->modified->id}"), phoneHeadersFor($recipient))->assertOk();
});

it('answers a hidden thread exactly like a missing one', function () {
    $private = privateThreadOf($this->owner, ['title' => 'Private work']);
    $file = ThreadDiffFile::factory()->create(['thread_diff_id' => ThreadDiff::factory()->create(['thread_id' => $private->id])->id]);
    $stranger = phoneHeadersFor(User::factory()->create());

    foreach (['/diff', "/diff/files/{$file->id}"] as $suffix) {
        $missing = $this->getJson('/api/app/v1/threads/zzzzzzzz' . $suffix, $stranger)->assertNotFound()->json();
        $this->getJson(appThreadPath($private, $suffix), $stranger)->assertNotFound()->assertExactJson($missing);
        $this->getJson("/api/app/v1/threads/{$private->id}{$suffix}", $stranger)->assertNotFound()->assertExactJson($missing);
    }
});

it('does not count file loads against the device limit', function () {
    $headers = phoneHeadersFor($this->owner);
    $path = appThreadPath($this->thread, "/diff/files/{$this->modified->id}");

    foreach (range(1, 300) as $ignored) {
        $this->getJson($path, $headers)->assertOk();
    }

    $this->getJson('/api/app/v1/me', $headers)->assertOk();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = appThreadPath($this->thread, '/diff');

    $this->getJson($path, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->getJson($path, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
