import { Dialog } from '@geocodio/console-ui';
import { useEffect } from 'react';
import type { MediaItem } from '@/types/tasks';

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

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title={item.caption ?? 'Media'}
            hideTitle
            width="w-[calc(100vw-2rem)]"
            className="h-[calc(100dvh-2rem)] max-w-none overflow-hidden p-0"
            data-testid="media-lightbox"
        >
            <div className="flex h-full flex-col items-center justify-center gap-3 bg-black p-4">
                {item.kind === 'video' ? (
                    <video controls autoPlay preload="metadata" className="max-h-full max-w-full rounded-card" src={item.url} />
                ) : (
                    <img src={item.url} alt={item.caption ?? ''} className="max-h-full max-w-full rounded-card object-contain" />
                )}
                {item.caption && <p className="shrink-0 text-center text-[12px] italic text-white/70">{item.caption}</p>}
            </div>
        </Dialog>
    );
}
