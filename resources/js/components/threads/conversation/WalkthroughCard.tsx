import { usePoll } from '@inertiajs/react';
import { Check, Clock } from 'lucide-react';
import type { ThreadWalkthrough } from '@/types/threads';

function clock(seconds: number): string {
    const whole = Math.round(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

/** The latest recording on the thread: progress while Claude records or the cut renders, then the video and screenshots. */
export function WalkthroughCard({ walkthrough }: { walkthrough: ThreadWalkthrough }) {
    const { isRecording, isRendering, video, screenshots, isInPullRequest } = walkthrough;

    usePoll(4000, { only: ['walkthrough'] }, { autoStart: isRecording || isRendering });

    return (
        <div className="flex gap-3" data-testid="thread-walkthrough">
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-accent-soft text-[11px] font-medium text-accent-text">C</div>
            <div className="min-w-0 flex-1">
                <div className="text-[12px] font-medium text-body">Walkthrough</div>
                {isRecording && (
                    <div className="mt-1 flex items-center gap-2 rounded-[8px] bg-panel-2 px-3 py-2 text-[12.5px] text-body" data-testid="walkthrough-recording">
                        <span className="h-2 w-2 shrink-0 animate-pulse rounded-pill bg-info motion-reduce:animate-none" aria-hidden="true" />
                        Claude is recording a walkthrough
                    </div>
                )}
                {!isRecording && isRendering && (
                    <div className="mt-1 flex items-center gap-2 rounded-[8px] bg-panel-2 px-3 py-2 text-[12.5px] text-muted" data-testid="walkthrough-rendering">
                        <Clock className="size-3.5" aria-hidden />
                        Rendering the cut. It shows up here when it is ready.
                    </div>
                )}
                {video !== null && (
                    <div className="mt-2 overflow-hidden rounded-[10px] border border-hair bg-panel" data-testid="walkthrough-video">
                        <video src={video.url} poster={video.thumbnailUrl ?? undefined} controls preload="metadata" className="aspect-video max-h-[320px] w-full bg-black" />
                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3.5 py-2.5 text-[12.5px]">
                            <span>
                                <span className="font-medium text-body">Walkthrough</span>
                                {video.durationSeconds > 0 && <span className="ml-2 tabular-nums text-muted">{clock(video.durationSeconds)}</span>}
                            </span>
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
                {(video !== null || screenshots.length > 0) && (
                    <p className="mt-2 inline-flex items-center gap-1.5 text-[12.5px] text-muted" data-testid="walkthrough-pr-note">
                        {isInPullRequest ? <Check className="size-3.5 text-ok" aria-hidden /> : null}
                        {isInPullRequest ? 'Added to the pull request description' : 'Will be added when you open the PR'}
                    </p>
                )}
            </div>
        </div>
    );
}
