import { IconButton, cn } from '@geocodio/console-ui';
import { X } from 'lucide-react';
import { useEffect, useRef, useState, type CSSProperties, type Ref } from 'react';
import type { Chapter } from '@/types/tasks';

function formatTimestamp(seconds: number): string {
    const total = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(total / 60);
    const secs = total % 60;
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

function withoutQuery(url: string): string {
    return url.split('?')[0];
}

/**
 * Space the dialog chrome takes around the video: the viewport margin,
 * the header row, the body padding, and (beside chapters) the rail plus gap.
 */
const CHROME_HEIGHT = '7.5rem';
const CHROME_WIDTH = '4rem';
const CHROME_WIDTH_WITH_RAIL = 'calc(5rem + 280px)';

/**
 * Ports the Blade `walkthrough-player` partial: a video element plus a
 * chapters rail. The initial seek position comes from the page's `?t=`
 * query param (seconds), matching the old Livewire/Alpine behaviour.
 *
 * Every page poll signs the cut's URL afresh, so the query string changes
 * while the file stays the same. The player keeps the URL it loaded until
 * the path itself changes, so a poll never restarts playback.
 *
 * On desktop the video is as large as the viewport allows at the cut's
 * aspect ratio, so the dialog wraps it without empty bands, and the
 * chapters rail matches the video's height.
 */
export function VideoPlayer({
    videoUrl,
    chapters,
    title,
    onClose,
    closeRef,
}: {
    videoUrl: string;
    chapters: Chapter[];
    title: string;
    onClose: () => void;
    closeRef?: Ref<HTMLButtonElement>;
}) {
    const playerRef = useRef<HTMLVideoElement>(null);
    const [source, setSource] = useState(videoUrl);
    if (withoutQuery(source) !== withoutQuery(videoUrl)) {
        setSource(videoUrl);
    }
    const [current, setCurrent] = useState(0);
    const [aspectRatio, setAspectRatio] = useState(16 / 10);
    const [duration, setDuration] = useState<number | null>(null);
    const seekAppliedRef = useRef(false);

    const seek = (seconds: number) => {
        const player = playerRef.current;
        if (!player) {
            return;
        }
        player.currentTime = seconds;
        player.play().catch(() => {});
    };

    const sync = () => {
        const time = playerRef.current?.currentTime ?? 0;
        let index = 0;
        chapters.forEach((chapter, i) => {
            if (time + 0.25 >= chapter.seconds) {
                index = i;
            }
        });
        setCurrent(index);
    };

    const readMetadata = () => {
        const player = playerRef.current;
        if (!player) {
            return;
        }
        if (player.videoWidth > 0 && player.videoHeight > 0) {
            setAspectRatio(player.videoWidth / player.videoHeight);
        }
        if (Number.isFinite(player.duration)) {
            setDuration(player.duration);
        }
    };

    const hasChapters = chapters.length > 0;
    const chromeWidth = hasChapters ? CHROME_WIDTH_WITH_RAIL : CHROME_WIDTH;
    const videoStyle = {
        aspectRatio,
        '--video-width': `min(calc(100vw - ${chromeWidth}), calc((100dvh - ${CHROME_HEIGHT}) * ${aspectRatio}))`,
    } as CSSProperties;
    const meta = [
        duration !== null ? formatTimestamp(duration) : null,
        hasChapters ? `${chapters.length} ${chapters.length === 1 ? 'chapter' : 'chapters'}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    useEffect(() => {
        const player = playerRef.current;
        if (!player || seekAppliedRef.current) {
            return;
        }
        const seekTo = new URLSearchParams(window.location.search).get('t');
        if (seekTo === null) {
            return;
        }
        const seconds = Number(seekTo);
        if (Number.isNaN(seconds)) {
            return;
        }
        const applySeek = () => {
            seekAppliedRef.current = true;
            seek(seconds);
        };
        if (player.readyState >= 1) {
            applySeek();
        } else {
            player.addEventListener('loadedmetadata', applySeek, { once: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="flex max-h-[calc(100dvh-2rem)] flex-col" data-testid="walkthrough-player">
            <div className="flex shrink-0 items-center gap-3 border-b border-hair px-4 py-2.5">
                <h2 className="min-w-0 flex-1 truncate text-[13px] font-semibold text-body" data-testid="walkthrough-title">
                    {title}
                </h2>
                {meta !== '' && <span className="shrink-0 text-[12px] tabular-nums text-faint">{meta}</span>}
                <IconButton ref={closeRef} label="Close walkthrough" onClick={onClose} className="h-7 w-7 border-0 shadow-none" data-testid="walkthrough-close">
                    <X size={14} />
                </IconButton>
            </div>

            <div className="flex min-h-0 flex-col gap-4 overflow-y-auto p-4 lg:flex-row lg:overflow-visible">
                <div className="w-full shrink-0 lg:w-(--video-width)" style={videoStyle} data-testid="walkthrough-cut">
                    <video
                        ref={playerRef}
                        onTimeUpdate={sync}
                        onLoadedMetadata={readMetadata}
                        controls
                        preload="metadata"
                        className="h-full w-full rounded-control bg-black"
                        src={source}
                    />
                </div>

                {hasChapters && (
                    <aside className="flex w-full flex-col lg:relative lg:w-[280px] lg:shrink-0">
                        <div className="flex flex-col lg:absolute lg:inset-0">
                            <h3 className="mb-2 shrink-0 text-[11px] font-semibold uppercase tracking-wide text-faint">Chapters</h3>
                            <ul className="min-h-0 space-y-1 overflow-y-auto pr-1" data-testid="walkthrough-chapters">
                                {chapters.map((chapter, index) => (
                                    <li key={index}>
                                        <button
                                            type="button"
                                            onClick={() => seek(chapter.seconds)}
                                            aria-current={current === index ? 'true' : 'false'}
                                            className={cn(
                                                'flex w-full items-baseline gap-2 rounded-control px-2 py-1.5 text-left text-[12px]',
                                                current === index ? 'bg-accent-soft text-accent-text' : 'text-muted hover:bg-panel-2',
                                            )}
                                            data-testid={`walkthrough-chapter-${index}`}
                                        >
                                            <span className="font-mono text-[11px] text-faint tabular-nums">{formatTimestamp(chapter.seconds)}</span>
                                            <span className="flex-1">{chapter.title}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </aside>
                )}
            </div>
        </div>
    );
}
