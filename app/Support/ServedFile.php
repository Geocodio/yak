<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The one place that decides how a stored file the agent produced is handed to a browser. The bytes are not
 * trusted, so every response carries `nosniff`, and anything a browser could run (HTML, SVG, XML, script,
 * unknown types) is sandboxed: HTML may run script only in an opaque origin (`sandbox allow-scripts`, never
 * `allow-same-origin`), and the rest is a download, never rendered inline. Only raster images, video and
 * PDF are shown inline as themselves.
 */
final class ServedFile
{
    private const array MEDIA_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'pdf' => 'application/pdf',
    ];

    /**
     * Whether the file is safe to show inline: media and PDF as themselves, HTML inside the sandbox.
     */
    public static function isInlineSafe(string $filename): bool
    {
        $extension = self::extension($filename);

        return $extension === 'html' || isset(self::MEDIA_TYPES[$extension]);
    }

    /**
     * @param  array<string, string>  $headers  Extra headers; they win over the defaults.
     */
    public static function response(string $path, string $filename, bool $inline, array $headers = []): BinaryFileResponse
    {
        $extension = self::extension($filename);
        $isMedia = isset(self::MEDIA_TYPES[$extension]);

        $defaults = [
            'Content-Type' => self::MEDIA_TYPES[$extension] ?? match ($extension) {
                'html' => 'text/html; charset=utf-8',
                'csv' => 'text/csv',
                default => 'application/octet-stream',
            },
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, private',
        ];

        if (! $isMedia) {
            $defaults['Content-Security-Policy'] = $extension === 'html' ? 'sandbox allow-scripts' : 'sandbox';
        }

        $response = response()->file($path, [...$defaults, ...$headers]);
        $response->setContentDisposition(
            $inline ? 'inline' : 'attachment',
            $filename,
            str_replace('%', '', Str::ascii($filename)),
        );

        return $response;
    }

    private static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
