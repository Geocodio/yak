<?php

namespace App\Services\Threads\Preview;

use App\DataTransferObjects\PreviewManifest;
use App\DataTransferObjects\ThreadPreviewManifestResult;
use App\Models\Thread;
use App\Services\RepositoryConfigParser;
use App\Services\Threads\Contracts\SandboxManager;

/**
 * Reads `.yak/preview.yml` from the primary repository's checkout inside the
 * thread's sandbox. Extra repositories never contribute.
 */
class ThreadPreviewManifestReader
{
    public function __construct(
        private SandboxManager $sandboxes,
        private RepositoryConfigParser $parser,
    ) {}

    public function read(Thread $thread): ThreadPreviewManifestResult
    {
        $result = $this->sandboxes->exec($thread, ['cat', $this->workspacePath($thread) . '/.yak/preview.yml']);

        if ($result->failed()) {
            return ThreadPreviewManifestResult::missing();
        }

        $parsed = $this->parser->parse('preview.yml', $result->output());

        if ($parsed['errors'] !== []) {
            return ThreadPreviewManifestResult::invalid(implode('; ', $parsed['errors']));
        }

        return ThreadPreviewManifestResult::found(PreviewManifest::fromArray($parsed['data']));
    }

    private function workspacePath(Thread $thread): string
    {
        $path = $thread->primaryRepository()->value('sandbox_path')
            ?? (string) config('yak.sandbox.workspace_path', '/workspace');

        return rtrim((string) $path, '/');
    }
}
