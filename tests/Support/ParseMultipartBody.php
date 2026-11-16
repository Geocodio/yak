<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The in-process server that Pest's browser plugin runs only parses
 * urlencoded bodies, so a multipart upload from the page arrives with no
 * fields and no files. This middleware parses the raw body the way PHP's own
 * SAPI would, so browser tests can submit forms that carry files.
 */
class ParseMultipartBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentType = (string) $request->header('Content-Type');

        if (! str_starts_with(strtolower($contentType), 'multipart/form-data') || $request->request->count() > 0 || $request->files->count() > 0) {
            return $next($request);
        }

        if (! preg_match('/boundary="?([^";]+)"?/i', $contentType, $boundary)) {
            return $next($request);
        }

        $fields = '';
        $fileSlots = [];
        $uploads = [];

        foreach (explode('--' . $boundary[1], $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            [$rawHeaders, $body] = explode("\r\n\r\n", $part, 2) + [1 => ''];
            $body = preg_replace('/\r\n$/', '', $body);

            if (! preg_match('/name="([^"]*)"/', $rawHeaders, $name)) {
                continue;
            }

            if (preg_match('/filename="([^"]*)"/', $rawHeaders, $filename)) {
                $path = tempnam(sys_get_temp_dir(), 'yakup');
                file_put_contents($path, $body);
                $mime = preg_match('/Content-Type:\s*([^\r\n]+)/i', $rawHeaders, $type) ? trim($type[1]) : null;
                $placeholder = '__upload_' . count($uploads);
                $uploads[$placeholder] = new UploadedFile($path, $filename[1], $mime, null, true);
                $fileSlots[] = urlencode($name[1]) . '=' . $placeholder;

                continue;
            }

            $fields .= urlencode($name[1]) . '=' . urlencode($body) . '&';
        }

        parse_str($fields, $parsedFields);
        parse_str(implode('&', $fileSlots), $parsedFiles);
        array_walk_recursive($parsedFiles, function (mixed &$value) use ($uploads): void {
            $value = $uploads[$value] ?? $value;
        });

        $request->request->replace($parsedFields);
        $request->files->replace($parsedFiles);

        return $next($request);
    }
}
