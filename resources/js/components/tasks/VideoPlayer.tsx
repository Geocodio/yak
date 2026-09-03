import { cn } from '@geocodio/console-ui';
import { useEffect, useRef, useState } from 'react';
import type { Chapter } from '@/types/tasks';

function formatTimestamp(seconds: number): string {
    const total = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(total / 60);
    const secs = total % 60;
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Ports the Blade `walkthrough-player` partial: a video element plus a
 * chapters rail. The initial seek position comes from the page's `?t=`
 * query param (seconds), matching the old Livewire/Alpine behaviour.
 */
export function VideoPlayer({ videoUrl, chapters }: { videoUrl: string; chapters: Chapter[] }) {
    const playerRef = useRef<HTMLVideoElement>(null);
    const [current, setCurrent] = useState(0);
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
        <div className="flex min-h-0 flex-col gap-4 lg:h-full lg:flex-row" data-testid="walkthrough-player">
            <div className="flex shrink-0 items-center justify-center lg:min-h-0 lg:min-w-0 lg:flex-1 lg:shrink" data-testid="walkthrough-cut">
                <video
                    ref={playerRef}
                    onTimeUpdate={sync}
                    controls
                    preload="metadata"
                    className="max-h-full max-w-full rounded-card border border-hair bg-black"
                    src={videoUrl}
                />
            </div>

            {chapters.length > 0 && (
                <aside className="flex min-h-0 w-full flex-col gap-4 lg:w-[280px] lg:shrink-0">
                    <div className="flex min-h-0 shrink-0 flex-col lg:max-h-full">
                        <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-faint">Chapters</h3>
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
                                        <span className="font-mono text-[11px] text-faint">{formatTimestamp(chapter.seconds)}</span>
                                        <span className="flex-1">{chapter.title}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                </aside>
            )}
        </div>
    );
}
