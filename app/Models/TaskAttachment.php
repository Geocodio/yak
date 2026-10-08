<?php

namespace App\Models;

use Database\Factories\TaskAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A file a person attached to a dashboard message. It belongs to the run
 * the message started (or, for a clarification reply, the run it resumed)
 * and is pushed into that run's sandbox so the agent can read it.
 */
class TaskAttachment extends Model
{
    /** @use HasFactory<TaskAttachmentFactory> */
    use HasFactory;

    public const string CONTEXT_REQUEST = 'request';

    public const string CONTEXT_CLARIFICATION_REPLY = 'clarification_reply';

    /**
     * Formats both a browser and Claude's Read tool render as an image.
     * Everything else is offered as a download, so an uploaded SVG or
     * HTML file can never execute script on this origin.
     *
     * @var list<string>
     */
    public const array IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * The label a message uses for an attachment: `Image #1`, `File #2`.
     * Written in the text as `[Image #1]`. Mirrored by `TOKEN` in
     * `resources/js/components/attachments/attachmentTokens.ts`.
     */
    public const string LABEL = '(?:Image|File) #\d+';

    public const string REFERENCE_PATTERN = '/^' . self::LABEL . '$/';

    /** A label as written in message text, capturing the label itself. */
    public const string TOKEN_PATTERN = '/\[(' . self::LABEL . ')\]/';

    /** @var list<string> */
    public const array VIDEO_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];

    /** @var list<string> */
    public const array AUDIO_MIME_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/flac'];

    /** @var list<string> */
    public const array TEXT_MIME_TYPES = [
        'application/json', 'application/xml', 'application/javascript', 'application/x-yaml', 'application/yaml',
        'application/sql', 'application/x-sh', 'application/x-httpd-php', 'image/svg+xml',
    ];

    /**
     * Extensions read as text whatever MIME type the upload was sniffed as
     * (logs and source files often come through as octet-stream).
     *
     * @var list<string>
     */
    public const array TEXT_EXTENSIONS = [
        'txt', 'log', 'md', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'ini', 'env', 'toml', 'sql', 'sh', 'diff', 'patch',
        'js', 'jsx', 'ts', 'tsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'html', 'css', 'svg',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleted(function (TaskAttachment $attachment): void {
            Storage::disk('artifacts')->deleteDirectory(dirname($attachment->disk_path));
        });
    }

    /**
     * Validation rules for the `attachments` field of a message form.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:' . (int) config('yak.attachments.max_files')],
            'attachments.*' => ['file', 'max:' . (int) config('yak.attachments.max_file_kb')],
            'attachment_refs' => ['nullable', 'array'],
            'attachment_refs.*' => ['nullable', 'string', 'max:24', 'regex:' . self::REFERENCE_PATTERN],
        ];
    }

    /**
     * Human messages for {@see rules()}, keyed per file so the composer can
     * flag the exact attachment that failed.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $megabytes = round((int) config('yak.attachments.max_file_kb') / 1024, 1);

        return [
            'attachments.max' => 'Attach up to :max files per message.',
            'attachments.*.max' => "Too large: attachments can be up to {$megabytes} MB each.",
            'attachments.*.file' => 'This file could not be uploaded.',
            'attachments.*.uploaded' => 'This file could not be uploaded.',
            'attachment_refs.*.regex' => 'Attachment labels look like "Image #1" or "File #2".',
        ];
    }

    /**
     * Store every uploaded attachment on a validated message request, each
     * with the label the text uses for it.
     *
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, self>
     */
    public static function storeFromRequest(Request $request, array $attributes = []): Collection
    {
        /** @var array<int, UploadedFile> $files */
        $files = $request->file('attachments', []);
        /** @var array<int, string|null> $references */
        $references = (array) $request->input('attachment_refs', []);

        return collect($files)->map(fn (UploadedFile $file, int $index): self => self::storeUpload($file, [
            ...$attributes,
            'reference' => $references[$index] ?? null,
            'uploaded_by_user_id' => $request->user()?->id,
        ]))->values();
    }

    /**
     * Write an upload to the artifacts disk and record it. Each file gets
     * its own directory so two uploads with the same name never collide.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function storeUpload(UploadedFile $file, array $attributes = []): self
    {
        $name = self::safeFilename($file->getClientOriginalName());
        $path = $file->storeAs('attachments/' . Str::ulid(), $name, 'artifacts');

        if ($path === false) {
            throw new \RuntimeException("Could not store attachment {$name}.");
        }

        return self::create([
            'context' => self::CONTEXT_REQUEST,
            ...$attributes,
            'disk_path' => $path,
            'original_name' => $name,
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    /**
     * Strip anything that is not safe in a path segment while keeping the
     * name recognisable to the person who uploaded it and to the agent.
     */
    public static function safeFilename(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = Str::limit(Str::slug(pathinfo($name, PATHINFO_FILENAME)), 80, '');

        $stem = $stem !== '' ? $stem : 'attachment';

        return $extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1
            ? "{$stem}.{$extension}"
            : $stem;
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * @return BelongsTo<PendingSteeringMessage, $this>
     */
    public function pendingSteeringMessage(): BelongsTo
    {
        return $this->belongsTo(PendingSteeringMessage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isImage(): bool
    {
        return in_array($this->mime_type, self::IMAGE_MIME_TYPES, strict: true);
    }

    /**
     * How the lightbox can show this file, or null when it can only be
     * downloaded. `text` covers anything readable as source -- including
     * HTML and SVG, which are always served as plain text so they never run.
     * Mirrored by `previewKind()` in `resources/js/components/attachments/previewKind.ts`.
     *
     * @return 'image'|'video'|'audio'|'pdf'|'text'|null
     */
    public function previewKind(): ?string
    {
        $mime = $this->mime_type;
        $extension = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return match (true) {
            $this->isImage() => 'image',
            in_array($mime, self::VIDEO_MIME_TYPES, strict: true) => 'video',
            in_array($mime, self::AUDIO_MIME_TYPES, strict: true) => 'audio',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'text/'),
            in_array($mime, self::TEXT_MIME_TYPES, strict: true),
            in_array($extension, self::TEXT_EXTENSIONS, strict: true) => 'text',
            default => null,
        };
    }

    public function absolutePath(): string
    {
        return Storage::disk('artifacts')->path($this->disk_path);
    }

    public function url(): string
    {
        return $this->signedRoute(['attachment' => $this]);
    }

    public function downloadUrl(): string
    {
        return $this->signedRoute(['attachment' => $this, 'download' => 1]);
    }

    /**
     * The expiry is pinned to the hour so the polled task page hands out
     * the same URL for an hour and the browser cache keeps working, while
     * every link still stays valid for at least a day.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function signedRoute(array $parameters): string
    {
        return URL::temporarySignedRoute('task-attachments.show', now()->startOfHour()->addHours(25), $parameters);
    }
}
