import { Dialog } from '@geocodio/console-ui';
import { ChevronLeft, ChevronRight, Download, FileAudio, X } from 'lucide-react';
import { useEffect, useState, type MouseEvent } from 'react';
import { CodeViewer } from '@/components/editor/CodeViewer';
import { detectLanguage } from '@/components/editor/languageForFile';
import type { MediaItem } from '@/types/tasks';

/** Text previews stop here; the rest of the file is a download away. */
const TEXT_PREVIEW_LIMIT = 500_000;

function TextPreview({ url, fileName }: { url: string; fileName: string }) {
    const [state, setState] = useState<{ text: string; truncated: boolean } | 'loading' | 'error'>('loading');

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');

        fetch(url, { signal: controller.signal })
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
            .then((text) => setState({ text: text.slice(0, TEXT_PREVIEW_LIMIT), truncated: text.length > TEXT_PREVIEW_LIMIT }))
            .catch((error: unknown) => {
                if (!(error instanceof DOMException && error.name === 'AbortError')) {
                    setState('error');
                }
            });

        return () => controller.abort();
    }, [url]);

    return (
        <div
            className="flex h-full w-full max-w-5xl flex-col overflow-hidden rounded-card border border-hair bg-panel shadow-overlay"
            data-testid="media-lightbox-text"
        >
            {state === 'loading' && <p className="p-4 text-[12px] text-muted">Loading…</p>}
            {state === 'error' && <p className="p-4 text-[12px] text-fail">This file could not be loaded. Try downloading it instead.</p>}
            {typeof state === 'object' && (
                <>
                    <div className="flex shrink-0 items-center justify-between gap-3 border-b border-hair px-4 py-2 text-[11px] text-muted">
                        <span className="truncate font-mono">{fileName}</span>
                        <span className="shrink-0" data-testid="media-lightbox-language">
                            {detectLanguage(fileName)?.label ?? 'Plain text'} · {state.text.split('\n').length.toLocaleString()} lines
                        </span>
                    </div>
                    <div className="min-h-0 flex-1">
                        <CodeViewer value={state.text} fileName={fileName} data-testid="media-lightbox-code" />
                    </div>
                    {state.truncated && (
                        <p className="shrink-0 border-t border-hair px-4 py-2 text-[11px] text-faint">Showing the first 500 KB. Download the file to see the rest.</p>
                    )}
                </>
            )}
        </div>
    );
}

function Preview({ item }: { item: MediaItem }) {
    switch (item.kind) {
        case 'video':
            return <video controls autoPlay preload="metadata" className="max-h-full max-w-full rounded-card shadow-overlay" src={item.url} />;
        case 'audio':
            return (
                <div className="flex w-[min(28rem,100%)] flex-col gap-4 rounded-card border border-hair bg-panel p-5 shadow-overlay" data-testid="media-lightbox-audio">
                    <div className="flex items-center gap-3 text-[13px] font-medium text-body">
                        <span className="flex size-9 items-center justify-center rounded-control bg-panel-2 text-muted">
                            <FileAudio size={18} aria-hidden />
                        </span>
                        <span className="truncate">{item.caption}</span>
                    </div>
                    <audio controls autoPlay src={item.url} className="w-full" />
                </div>
            );
        case 'pdf':
            return (
                <iframe
                    src={item.url}
                    title={item.caption ?? 'PDF'}
                    className="h-full w-full max-w-5xl rounded-card border border-hair bg-white shadow-overlay"
                    data-testid="media-lightbox-pdf"
                />
            );
        case 'text':
            return <TextPreview url={item.url} fileName={item.fileName ?? item.caption ?? 'file.txt'} />;
        default:
            return <img src={item.url} alt={item.caption ?? ''} className="max-h-full max-w-full rounded-card object-contain shadow-overlay" />;
    }
}

/**
 * Full-screen viewer over a dimmed page. Images, video, audio, PDFs and text
 * files show inline; arrows (buttons or keys) step through the set, and a
 * click on the dimmed space around the content, the close button or Escape
 * all close it.
 */
export function MediaLightbox({
    media,
    index,
    onOpenChange,
    onIndexChange,
}: {
    media: MediaItem[] | null;
    index: number;
    onOpenChange: (open: boolean) => void;
    onIndexChange: (index: number) => void;
}) {
    const open = media !== null;

    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            // Arrow keys seek in a focused player and move the cursor in the
            // code view, rather than switching files.
            if (event.target instanceof HTMLMediaElement || (event.target instanceof Element && event.target.closest('.cm-editor'))) {
                return;
            }
            if (event.key === 'ArrowRight') {
                onIndexChange(Math.min((media?.length ?? 1) - 1, index + 1));
            } else if (event.key === 'ArrowLeft') {
                onIndexChange(Math.max(0, index - 1));
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, index, media, onIndexChange]);

    if (!open || !media) {
        return null;
    }

    const item = media[index];
    const hasMany = media.length > 1;
    const control =
        'flex size-9 items-center justify-center rounded-pill bg-black/40 text-white backdrop-blur transition-colors hover:bg-black/60 disabled:pointer-events-none disabled:opacity-30';
    const closeOnBackdrop = (event: MouseEvent<HTMLDivElement>) => {
        if (event.target === event.currentTarget) {
            onOpenChange(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={item.caption ?? 'Media'}
            hideTitle
            width="w-screen"
            className="h-dvh max-w-none rounded-none border-0 bg-black/40 p-0 shadow-none"
            data-testid="media-lightbox"
        >
            <div className="flex h-full flex-col" onClick={closeOnBackdrop}>
                <div className="flex shrink-0 items-center justify-end gap-2 p-3" onClick={closeOnBackdrop}>
                    {(item.downloadUrl ?? item.kind !== 'image') && (
                        <a
                            href={item.downloadUrl ?? item.url}
                            download={item.downloadName ?? ''}
                            className={control}
                            aria-label="Download"
                            title="Download"
                            data-testid="media-lightbox-download"
                        >
                            <Download size={16} aria-hidden />
                        </a>
                    )}
                    <button type="button" className={control} onClick={() => onOpenChange(false)} aria-label="Close" data-testid="media-lightbox-close">
                        <X size={17} aria-hidden />
                    </button>
                </div>
                <div
                    className="relative flex min-h-0 flex-1 items-center justify-center px-4 sm:px-16"
                    onClick={closeOnBackdrop}
                    data-testid="media-lightbox-stage"
                >
                    {hasMany && (
                        <>
                            <button
                                type="button"
                                className={`${control} absolute top-1/2 left-3 size-10 -translate-y-1/2 max-sm:hidden`}
                                onClick={() => onIndexChange(index - 1)}
                                disabled={index === 0}
                                aria-label="Previous"
                                data-testid="media-lightbox-prev"
                            >
                                <ChevronLeft size={20} aria-hidden />
                            </button>
                            <button
                                type="button"
                                className={`${control} absolute top-1/2 right-3 size-10 -translate-y-1/2 max-sm:hidden`}
                                onClick={() => onIndexChange(index + 1)}
                                disabled={index === media.length - 1}
                                aria-label="Next"
                                data-testid="media-lightbox-next"
                            >
                                <ChevronRight size={20} aria-hidden />
                            </button>
                        </>
                    )}
                    <Preview key={item.url} item={item} />
                </div>
                <div className="flex shrink-0 items-center justify-center gap-3 px-4 py-3" onClick={closeOnBackdrop}>
                    {hasMany && (
                        <button type="button" className={`${control} sm:hidden`} onClick={() => onIndexChange(index - 1)} disabled={index === 0} aria-label="Previous">
                            <ChevronLeft size={18} aria-hidden />
                        </button>
                    )}
                    {(item.caption || hasMany) && (
                        <p className="rounded-pill bg-black/40 px-3 py-1 text-center text-[12px] text-white/90 backdrop-blur">
                            {item.caption}
                            {hasMany && <span className="ml-2 text-white/60 tabular-nums">{`${index + 1} / ${media.length}`}</span>}
                        </p>
                    )}
                    {hasMany && (
                        <button
                            type="button"
                            className={`${control} sm:hidden`}
                            onClick={() => onIndexChange(index + 1)}
                            disabled={index === media.length - 1}
                            aria-label="Next"
                        >
                            <ChevronRight size={18} aria-hidden />
                        </button>
                    )}
                </div>
            </div>
        </Dialog>
    );
}
