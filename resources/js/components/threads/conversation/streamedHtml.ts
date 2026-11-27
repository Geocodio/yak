import { normalizeHtml } from '../../../threads/streaming/streamingMarkdown.ts';

/**
 * The streamed HTML stays on screen when it matches the server's final HTML, so the swap changes nothing the reader
 * sees; any difference takes the server's version.
 */
export function pickHtml(streamedHtml: string | null, finalHtml: string): string {
    return streamedHtml !== null && normalizeHtml(streamedHtml) === normalizeHtml(finalHtml) ? streamedHtml : finalHtml;
}
