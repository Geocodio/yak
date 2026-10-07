<?php

namespace App\Support;

use App\Models\TaskAttachment;

/**
 * Turns `[Image #1]`-style labels in rendered message HTML into chips the
 * thread can link to the attachment they name. Labels inside code are
 * left alone, as is any label that names no attachment on the message.
 */
final class AttachmentReferences
{
    /**
     * @param  array<int, string>  $references  Labels of the message's attachments, e.g. `Image #1`
     */
    public static function linkInHtml(string $html, array $references): string
    {
        if ($references === [] || $html === '') {
            return $html;
        }

        $segments = preg_split('/(<pre\b.*?<\/pre>|<code\b.*?<\/code>)/si', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        return implode('', array_map(
            fn (string $segment, int $index): string => $index % 2 === 1 ? $segment : (string) preg_replace_callback(
                TaskAttachment::TOKEN_PATTERN,
                fn (array $match): string => in_array($match[1], $references, strict: true)
                    ? sprintf('<span class="attachment-ref" data-attachment-ref="%1$s">[%1$s]</span>', e($match[1]))
                    : $match[0],
                $segment,
            ),
            $segments,
            array_keys($segments),
        ));
    }
}
