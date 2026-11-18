import { Button } from '@geocodio/console-ui';
import { Check, ChevronDown, Clock, Play, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState, type RefObject } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { retryRender } from '@/routes/threads';
import { requestWalkthrough } from '@/threads/panels/changes/OpenPullRequestButton';
import type { EarlierWalkthrough, ThreadWalkthrough } from '@/types/threads';

function clock(seconds: number): string {
    const whole = Math.round(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

function timeOf(iso: string | null): string | null {
    return iso === null ? null : new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function ClaudeAvatar() {
    return <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-accent-soft text-[11px] font-medium text-accent-text">C</div>;
}

function WalkthroughMedia({ number, video, screenshots, videoRef }: { number: number; video: ThreadWalkthrough['video']; screenshots: ThreadWalkthrough['screenshots']; videoRef?: RefObject<HTMLVideoElement | null> }) {
    return (
        <>
            {video !== null && (
                <div className="mt-2 overflow-hidden rounded-[10px] border border-hair bg-panel" data-testid="walkthrough-video">
                    <video ref={videoRef} src={video.url} poster={video.thumbnailUrl ?? undefined} controls preload="metadata" aria-label={`Walkthrough ${number}`} className="aspect-video max-h-[320px] w-full bg-black" />
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3.5 py-2.5 text-[12.5px]">
                        <span>{video.durationSeconds > 0 && <span className="tabular-nums text-muted">{clock(video.durationSeconds)}</span>}</span>
                        <span className="flex flex-wrap gap-1.5">
                            {video.chapters.map((chapter) => (
                                <span key={chapter.startSeconds} className="rounded-pill bg-panel-2 px-2 py-0.5 text-[11.5px] text-muted">{chapter.title}</span>
                            ))}
                        </span>
                    </div>
                </div>
            )}
            {screenshots.length > 0 && (
                <div className="mt-2 grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2" data-testid="walkthrough-screenshots">
                    {screenshots.map((shot) => (
                        <a key={shot.url} href={shot.url} target="_blank" rel="noreferrer" className="overflow-hidden rounded-[8px] border border-hair text-[11.5px] text-muted hover:bg-panel-2">
                            <img src={shot.url} alt={shot.caption ?? 'Screenshot'} className="aspect-[4/3] w-full object-cover object-top" loading="lazy" />
                            {shot.caption !== null && <span className="block px-2 py-1.5">{shot.caption}</span>}
                        </a>
                    ))}
                </div>
            )}
        </>
    );
}

/** The row at the end of the thread while Claude records the next version. */
export function WalkthroughRecordingRow({ number }: { number: number }) {
    return (
        <div className="flex gap-3" data-testid="thread-walkthrough-recording">
            <ClaudeAvatar />
            <div className="min-w-0 flex-1">
                <div className="text-[12px] font-medium text-body">Walkthrough {number}</div>
                <div className="mt-1 flex items-center gap-2 rounded-[8px] bg-panel-2 px-3 py-2 text-[12.5px] text-body" data-testid="walkthrough-recording">
                    <span className="h-2 w-2 shrink-0 animate-pulse rounded-pill bg-info motion-reduce:animate-none" aria-hidden="true" />
                    Claude is recording a walkthrough
                </div>
            </div>
        </div>
    );
}

/** An older version, folded to one row where it was recorded; the video loads only once it is opened. */
export function EarlierWalkthroughCard({ walkthrough }: { walkthrough: EarlierWalkthrough }) {
    const [isOpen, setIsOpen] = useState(false);
    const { number, video, screenshots, recordedAt } = walkthrough;
    const details = [video !== null && video.durationSeconds > 0 ? clock(video.durationSeconds) : null, screenshots.length > 0 ? `${screenshots.length} ${screenshots.length === 1 ? 'screenshot' : 'screenshots'}` : null, timeOf(recordedAt) === null ? null : `recorded ${timeOf(recordedAt)}`].filter((detail) => detail !== null);

    return (
        <div className="ml-9 overflow-hidden rounded-[10px] border border-hair bg-panel" data-testid="earlier-walkthrough" data-number={number}>
            <button type="button" aria-expanded={isOpen} onClick={() => setIsOpen(!isOpen)} className="flex min-h-11 w-full cursor-pointer items-center gap-3 p-2.5 text-left hover:bg-panel-2" data-testid="earlier-walkthrough-toggle">
                <span className="flex h-[45px] w-20 shrink-0 items-center justify-center overflow-hidden rounded-[6px] bg-panel-2 text-muted">
                    {video?.thumbnailUrl ? <img src={video.thumbnailUrl} alt="" className="h-full w-full object-cover" loading="lazy" /> : <Play className="size-4" aria-hidden />}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2 text-[12.5px] font-medium text-body">
                        Walkthrough {number}
                        <span className="rounded-pill bg-panel-2 px-2 py-px text-[11.5px] font-normal text-muted">Earlier version</span>
                    </span>
                    <span className="block text-[12px] text-muted">{details.join(', ')}</span>
                </span>
                <ChevronDown className={`size-4 shrink-0 text-muted transition-transform motion-reduce:transition-none ${isOpen ? 'rotate-180' : ''}`} aria-hidden />
            </button>
            {isOpen && (
                <div className="px-2.5 pb-2.5" data-testid="earlier-walkthrough-media">
                    <WalkthroughMedia number={number} video={video} screenshots={screenshots} />
                </div>
            )}
        </div>
    );
}

/** The newest version, where it was recorded: progress while the video renders, then the video and screenshots. */
export function WalkthroughCard({ walkthrough, threadId, canRetryRender, canRecordAgain, startAt = null }: { walkthrough: ThreadWalkthrough; threadId: string; canRetryRender: boolean; canRecordAgain: boolean; startAt?: number | null }) {
    const { number, isRecording, isRendering, isRenderFailed, renderError, video, screenshots, isInPullRequest, isOutdated, recordedAt } = walkthrough;
    const action = useRouterAction();
    const videoRef = useRef<HTMLVideoElement>(null);
    const hasVideo = video !== null;
    const hasMedia = hasVideo || screenshots.length > 0;

    // A `?walkthrough=<seconds>` link opens the video at that time, once the video is there.
    useEffect(() => {
        const element = videoRef.current;

        if (startAt === null || !hasVideo || element === null) {
            return;
        }
        const seek = () => {
            element.currentTime = startAt;
            element.scrollIntoView({ block: 'center' });
        };
        if (element.readyState >= 1) {
            seek();
        } else {
            element.addEventListener('loadedmetadata', seek, { once: true });
        }

        return () => element.removeEventListener('loadedmetadata', seek);
    }, [startAt, hasVideo]);

    return (
        <div className="flex gap-3" data-testid="thread-walkthrough" data-number={number}>
            <ClaudeAvatar />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2 text-[12px] font-medium text-body">
                    Walkthrough {number}
                    {isRenderFailed ? <span className="inline-flex h-[18px] items-center rounded-pill bg-fail-soft px-2 text-[11.5px] text-fail" data-testid="walkthrough-failed-pill">Failed</span> : hasMedia && <span className="inline-flex h-[18px] items-center rounded-pill bg-ok-soft px-2 text-[11.5px] text-ok" data-testid="walkthrough-latest">Latest</span>}
                    {timeOf(recordedAt) !== null && <span className="font-normal text-muted">{timeOf(recordedAt)}</span>}
                </div>
                {isRenderFailed && (
                    <div role="alert" className="mt-1 rounded-[8px] bg-fail-soft px-3 py-2 text-[12.5px] text-fail" data-testid="walkthrough-failed">
                        <p className="flex items-start gap-2">
                            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            <span>{renderError}</span>
                        </p>
                        {walkthrough.canRetryRender && canRetryRender && (
                            <Button
                                variant="tertiary"
                                className="mt-2"
                                pending={action.isPending('retry-render')}
                                pendingLabel="Queueing…"
                                onClick={() => action.run('retry-render', 'post', retryRender.url(threadId))}
                                data-testid="retry-render"
                            >
                                Retry render
                            </Button>
                        )}
                    </div>
                )}
                {isRendering && (
                    <div className="mt-1 flex items-center gap-2 rounded-[8px] bg-panel-2 px-3 py-2 text-[12.5px] text-muted" data-testid="walkthrough-rendering">
                        <Clock className="size-3.5" aria-hidden />
                        Rendering the video. It shows up here when it is ready.
                    </div>
                )}
                <WalkthroughMedia number={number} video={video} screenshots={screenshots} videoRef={videoRef} />
                {isOutdated && hasMedia && !isRecording && (
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-[8px] bg-warn-soft px-3 py-2 text-[12.5px] text-warn" data-testid="walkthrough-outdated">
                        <span>Claude changed code after this recording. The video does not show those changes.</span>
                        {canRecordAgain && (
                            <Button variant="tertiary" onClick={() => requestWalkthrough(threadId, 'record_walkthrough', false)} data-testid="walkthrough-record-again">
                                Record again
                            </Button>
                        )}
                    </div>
                )}
                {hasMedia && (
                    <p className="mt-2 inline-flex items-center gap-1.5 text-[12.5px] text-muted" data-testid="walkthrough-pr-note">
                        {isInPullRequest ? <Check className="size-3.5 text-ok" aria-hidden /> : null}
                        {isInPullRequest ? `In the pull request description.${number > 1 ? ` Replaced walkthrough ${number - 1}.` : ''}` : 'Will be added when you open the PR'}
                    </p>
                )}
            </div>
        </div>
    );
}
