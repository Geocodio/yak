<?php

namespace App\Services\Tasks;

use App\Models\Artifact;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Support\Facades\URL;

/**
 * Turns the session-only links of the web task page (PR images, attachments,
 * the research viewer, the long-lived media links) into short-lived signed
 * links that a web view in the app opens with no session and no protocol
 * header. Only links that belong to the conversation of the task shown are
 * signed; any other link is left as it is.
 */
final class AppMediaLinks
{
    public const int LINK_MINUTES = 30;

    private const string URL_CHARACTERS = '[^\s"\'<>]';

    /** @var array<string, YakTask>|null public id => task, for the conversation of the task shown */
    private ?array $conversationByPublicId = null;

    /** @var array<int, TaskAttachment>|null */
    private ?array $attachmentsById = null;

    /** @var array<int, array<string, Artifact>>|null */
    private ?array $artifactsByTask = null;

    public function __construct(private readonly YakTask $task) {}

    /**
     * Rewrites every string inside the value, keys untouched.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function rewriteTree(array $value): array
    {
        return array_map(fn (mixed $item): mixed => match (true) {
            is_string($item) => $this->rewrite($item),
            is_array($item) => $this->rewriteTree($item),
            default => $item,
        }, $value);
    }

    public function rewrite(string $html): string
    {
        if (! str_contains($html, '/')) {
            return $html;
        }

        $characters = self::URL_CHARACTERS;

        if (str_contains($html, '/pr-images/')) {
            $html = preg_replace_callback(
                "#https?://{$characters}+?/tasks/([A-Za-z0-9]+)/pr-images/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i",
                fn (array $match): string => $this->conversationTask($match[1]) !== null ? self::forPullRequestImage($match[1], $match[2]) : $match[0],
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '/task-attachments/')) {
            $html = preg_replace_callback(
                "#https?://{$characters}+?(?<!app-media)/task-attachments/(\d+)(\?{$characters}*)?#",
                function (array $match): string {
                    $attachment = $this->attachments()[(int) $match[1]] ?? null;

                    return $attachment !== null
                        ? self::forAttachment($attachment, str_contains($match[2] ?? '', 'download=1'))
                        : $match[0];
                },
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '/viewer/')) {
            $html = preg_replace_callback(
                "#https?://{$characters}+?/artifacts/([A-Za-z0-9]+)/viewer/([^\s\"'<>?]+)#",
                fn (array $match): string => $this->forConversationArtifact($match[1], rawurldecode($match[2])) ?? $match[0],
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '/artifacts/') && str_contains($html, 'signature=')) {
            $html = preg_replace_callback(
                "#https?://{$characters}+?/artifacts/([A-Za-z0-9]+)/([^\s\"'<>/?]+)\?{$characters}*signature={$characters}*#",
                fn (array $match): string => $this->forConversationArtifact($match[1], rawurldecode($match[2])) ?? $match[0],
                $html,
            ) ?? $html;
        }

        return $html;
    }

    public static function forAttachment(TaskAttachment $attachment, bool $isDownload = false): string
    {
        return URL::temporarySignedRoute(
            'app.media.task-attachments',
            now()->addMinutes(self::LINK_MINUTES),
            ['attachment' => $attachment->id] + ($isDownload ? ['download' => 1] : []),
        );
    }

    public static function forPullRequestImage(string $taskPublicId, string $asset): string
    {
        return URL::temporarySignedRoute(
            'app.media.pr-images',
            now()->addMinutes(self::LINK_MINUTES),
            ['task' => $taskPublicId, 'asset' => $asset],
        );
    }

    /**
     * The stored file opens through the existing signed file route, so the app
     * loses only the web's viewer frame around it.
     */
    public static function forArtifact(Artifact $artifact): string
    {
        return URL::temporarySignedRoute(
            'artifacts.show',
            now()->addMinutes(self::LINK_MINUTES),
            ['task' => $artifact->taskPublicId(), 'filename' => $artifact->filename],
        );
    }

    private function forConversationArtifact(string $taskPublicId, string $filename): ?string
    {
        $task = $this->conversationTask($taskPublicId);
        $artifact = $task === null ? null : ($this->artifacts()[$task->id][$filename] ?? null);

        return $artifact === null ? null : self::forArtifact($artifact);
    }

    private function conversationTask(string $publicId): ?YakTask
    {
        return $this->conversation()[$publicId] ?? null;
    }

    /**
     * @return array<int, TaskAttachment> keyed by id, loaded once for the conversation
     */
    private function attachments(): array
    {
        return $this->attachmentsById ??= TaskAttachment::query()
            ->whereIn('yak_task_id', array_map(fn (YakTask $task): int => $task->id, $this->conversation()))
            ->get()->keyBy('id')->all();
    }

    /**
     * @return array<int, array<string, Artifact>> task id => filename => artifact, loaded once
     */
    private function artifacts(): array
    {
        if ($this->artifactsByTask === null) {
            $this->artifactsByTask = [];
            $artifacts = Artifact::query()->whereIn('yak_task_id', array_map(fn (YakTask $task): int => $task->id, $this->conversation()))->get();

            foreach ($artifacts as $artifact) {
                $this->artifactsByTask[$artifact->yak_task_id][$artifact->filename] ??= $artifact;
            }
        }

        return $this->artifactsByTask;
    }

    /**
     * @return array<string, YakTask>
     */
    private function conversation(): array
    {
        return $this->conversationByPublicId ??= $this->task->conversation()->keyBy('public_id')->all();
    }
}
