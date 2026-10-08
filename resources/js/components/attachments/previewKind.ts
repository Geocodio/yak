/**
 * How the lightbox can show a file, or null when it can only be downloaded.
 * Mirrors `TaskAttachment::previewKind()` so a draft file previews the same
 * way it will once sent.
 */
export type PreviewKind = 'image' | 'video' | 'audio' | 'pdf' | 'text';

const IMAGE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
const VIDEO = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
const AUDIO = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/flac'];
const TEXT = [
    'application/json',
    'application/xml',
    'application/javascript',
    'application/x-yaml',
    'application/yaml',
    'application/sql',
    'application/x-sh',
    'application/x-httpd-php',
    'image/svg+xml',
];
const TEXT_EXTENSIONS = [
    'txt', 'log', 'md', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml', 'ini', 'env', 'toml', 'sql', 'sh', 'diff', 'patch',
    'js', 'jsx', 'ts', 'tsx', 'php', 'py', 'rb', 'go', 'rs', 'java', 'html', 'css', 'svg',
];

export function previewKind(mimeType: string, name: string): PreviewKind | null {
    const extension = name.includes('.') ? (name.split('.').pop() ?? '').toLowerCase() : '';

    if (IMAGE.includes(mimeType)) {
        return 'image';
    }
    if (VIDEO.includes(mimeType)) {
        return 'video';
    }
    if (AUDIO.includes(mimeType)) {
        return 'audio';
    }
    if (mimeType === 'application/pdf') {
        return 'pdf';
    }
    if (mimeType.startsWith('text/') || TEXT.includes(mimeType) || TEXT_EXTENSIONS.includes(extension)) {
        return 'text';
    }
    return null;
}
