<?php

namespace App\Services;

use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Copies a message's attachments into the sandbox, outside the repository
 * checkout so they are never committed, and describes them for the prompt
 * so the agent knows to open them.
 */
class TaskAttachmentStager
{
    public const string SANDBOX_DIRECTORY = '/home/yak/attachments';

    public function __construct(private readonly IncusSandboxManager $sandbox) {}

    /**
     * Push each attachment into the container and return the prompt section
     * that lists them, or an empty string when none could be staged. A file
     * that fails to copy is logged and left out rather than failing the run.
     *
     * @param  array<int, TaskAttachment>  $attachments
     */
    public function stage(string $containerName, array $attachments): string
    {
        $staged = [];

        foreach ($attachments as $attachment) {
            $remoteDirectory = self::SANDBOX_DIRECTORY . '/' . $attachment->id;
            $remotePath = $remoteDirectory . '/' . $attachment->original_name;

            try {
                if (! is_file($attachment->absolutePath())) {
                    throw new \RuntimeException('File is missing from the artifacts disk.');
                }

                $this->runAsRoot($containerName, 'mkdir -p ' . escapeshellarg($remoteDirectory));
                $this->sandbox->pushFile($containerName, $attachment->absolutePath(), $remotePath);
                $this->runAsRoot($containerName, sprintf(
                    'chown yak:yak %s && chown -R yak:yak %s',
                    escapeshellarg(self::SANDBOX_DIRECTORY),
                    escapeshellarg($remoteDirectory),
                ));

                $staged[] = ['path' => $remotePath, 'attachment' => $attachment];
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Could not stage attachment into sandbox', [
                    'container' => $containerName,
                    'attachment_id' => $attachment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($staged === []) {
            return '';
        }

        $lines = array_map(fn (array $file): string => sprintf(
            '- %s`%s` (%s, %s)',
            $file['attachment']->reference !== null ? "[{$file['attachment']->reference}] " : '',
            $file['path'],
            $file['attachment']->mime_type,
            Number::fileSize($file['attachment']->size_bytes),
        ), $staged);

        return "## Attached files\n\n"
            . 'The person attached these files to their message. Open each one with the Read tool before you start (it renders images, screenshots and PDFs). '
            . 'Where the message mentions a label like [Image #1], it means the file listed with that label. '
            . "They live outside the repository: don't copy them into it unless you are asked to.\n\n"
            . implode("\n", $lines);
    }

    private function runAsRoot(string $containerName, string $command): void
    {
        $result = $this->sandbox->run($containerName, $command, timeout: 10, asRoot: true);

        if ($result->failed()) {
            throw new \RuntimeException("`{$command}` failed: " . trim($result->errorOutput()));
        }
    }
}
