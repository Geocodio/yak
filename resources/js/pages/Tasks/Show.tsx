import { Head, usePoll } from '@inertiajs/react';
import { Dialog, Sheet } from '@geocodio/console-ui';
import { useEffect, useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { ActivityLog } from '@/components/tasks/ActivityLog';
import { Composer } from '@/components/tasks/Composer';
import { DebugDetails } from '@/components/tasks/DebugDetails';
import { DeploymentCard } from '@/components/tasks/DeploymentCard';
import { HeaderBand } from '@/components/tasks/HeaderBand';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import { ProgressList } from '@/components/tasks/ProgressList';
import { ThreadEntry } from '@/components/tasks/ThreadEntry';
import { TranscriptOverlay } from '@/components/tasks/TranscriptOverlay';
import { VideoPlayer } from '@/components/tasks/VideoPlayer';
import { WalkthroughCard } from '@/components/tasks/WalkthroughCard';
import { replaceTaskQuery } from '@/lib/taskQuery';
import type { PageProps } from '@/types/shared';
import type {
    ActionsData,
    ActivityData,
    ComposerData,
    DebugData,
    DeploymentData,
    FindingsData,
    MediaItem,
    ProgressStep,
    RunSummary,
    TaskDetail,
    ThreadEntryData,
    TranscriptEntry,
    WalkthroughData,
} from '@/types/tasks';

type Props = PageProps<{
    task: TaskDetail;
    thread: ThreadEntryData[];
    runs: RunSummary[];
    attempts: number[];
    activity: ActivityData;
    progress: { steps: ProgressStep[] };
    media: MediaItem[];
    walkthrough: WalkthroughData;
    deployment: DeploymentData;
    findings: FindingsData;
    composer: ComposerData;
    debug: DebugData;
    actions: ActionsData;
    pollInterval: number;
    transcriptLogId: number | null;
    transcript?: TranscriptEntry[];
}>;

export default function Show({
    task,
    thread,
    runs,
    attempts,
    activity,
    progress,
    media,
    walkthrough,
    deployment,
    findings,
    composer,
    debug,
    actions,
    pollInterval,
    transcriptLogId,
    transcript,
}: Props) {
    usePoll(pollInterval);

    const [transcriptOpen, setTranscriptOpen] = useState(transcriptLogId !== null);
    const [openLogId, setOpenLogId] = useState<number | null>(transcriptLogId);
    const [detailsDrawerOpen, setDetailsDrawerOpen] = useState(false);
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const [composerFill, setComposerFill] = useState<string | null>(null);
    const [walkthroughOpen, setWalkthroughOpen] = useState(false);

    const lastYakIndex = thread.reduce((acc, entry, index) => (entry.kind === 'yak' ? index : acc), -1);

    useEffect(() => {
        setOpenLogId(transcriptLogId);
        setTranscriptOpen(transcriptLogId !== null);
    }, [transcriptLogId]);

    const openTranscriptAt = (logId: number) => {
        setOpenLogId(logId);
        setTranscriptOpen(true);
        // Updates the deep link without a round trip; TranscriptOverlay fetches
        // the transcript itself (`only: ['transcript']`) if it isn't loaded yet.
        replaceTaskQuery({ log: logId });
    };

    const openTranscriptCold = () => {
        setTranscriptOpen(true);
    };

    const closeTranscript = (open: boolean) => {
        setTranscriptOpen(open);
        if (!open) {
            setOpenLogId(null);
            replaceTaskQuery({ log: undefined });
        }
    };

    const currentRunId = runs.find((run) => run.live)?.id ?? runs[runs.length - 1]?.id ?? task.id;

    const sidebar = (hiddenBelowLg: boolean) => (
        <aside
            className={hiddenBelowLg ? 'hidden w-[320px] shrink-0 flex-col gap-5 overflow-auto border-l border-hair bg-sidebar px-4 py-5 lg:flex' : 'flex flex-col gap-5'}
            data-testid="task-sidebar"
        >
            {(task.status === 'running' ||
                task.status === 'pending' ||
                task.status === 'awaiting_clarification' ||
                task.status === 'awaiting_ci' ||
                task.status === 'retrying') && <ProgressList steps={progress.steps} />}

            {activity.entries > 0 && (
                <ActivityLog
                    taskId={task.id}
                    activity={activity}
                    runs={runs}
                    currentRunId={currentRunId}
                    attempts={attempts}
                    currentAttempt={task.attempt}
                    openLogId={openLogId}
                    onOpenTranscript={openTranscriptAt}
                    onOpenTranscriptCold={openTranscriptCold}
                />
            )}

            <WalkthroughCard taskId={task.id} walkthrough={walkthrough} canRetryRender={actions.canRetryRender} onOpen={() => setWalkthroughOpen(true)} />

            {media.length > 0 && (
                <section data-testid="latest-media">
                    <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-faint">Latest media</h2>
                    <div className="flex flex-wrap gap-2">
                        {media.map((item, i) => (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => {
                                    setLightboxMedia(media);
                                    setLightboxIndex(i);
                                }}
                                className="block w-[110px] shrink-0 overflow-hidden rounded-control border border-hair text-left"
                                data-testid={`latest-media-thumb-${item.id}`}
                            >
                                {item.kind === 'video' ? (
                                    <video muted preload="metadata" className="h-[70px] w-full bg-panel-2 object-cover" src={item.url} />
                                ) : (
                                    <img src={item.thumbUrl ?? item.url} alt={item.caption ?? ''} loading="lazy" className="h-[70px] w-full object-cover" />
                                )}
                            </button>
                        ))}
                    </div>
                </section>
            )}

            <DeploymentCard deployment={deployment} />

            <DebugDetails debug={debug} />
        </aside>
    );

    return (
        <>
            <Head title={task.headline} />

            <HeaderBand
                task={task}
                actions={actions}
                deployment={deployment}
                onOpenTranscript={openTranscriptCold}
                onOpenDetailsDrawer={() => setDetailsDrawerOpen(true)}
            />

            <div className="flex min-h-0 flex-1">
                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="min-h-0 flex-1 overflow-auto">
                        <div className="mx-auto max-w-[820px] px-8 py-6">
                            <div className="flex flex-col gap-6">
                                {thread.map((entry, index) => (
                                    <ThreadEntry
                                        key={index}
                                        entry={entry}
                                        findings={index === lastYakIndex ? findings : null}
                                        onOpenMedia={(items, i) => {
                                            setLightboxMedia(items);
                                            setLightboxIndex(i);
                                        }}
                                        onSelectClarificationOption={(option) => setComposerFill(option)}
                                    />
                                ))}
                            </div>
                        </div>
                    </div>

                    <Composer taskId={task.id} composer={composer} fillValue={composerFill} />
                </div>

                {sidebar(true)}
            </div>

            <Sheet open={detailsDrawerOpen} onOpenChange={setDetailsDrawerOpen} title="Details" side="bottom">
                {sidebar(false)}
            </Sheet>

            <TranscriptOverlay
                open={transcriptOpen}
                onOpenChange={closeTranscript}
                entries={transcript}
                headline={task.headline}
                runs={runs}
                currentRunId={currentRunId}
                attempts={attempts}
                currentAttempt={task.attempt}
                selectedLogId={openLogId}
            />

            <MediaLightbox
                media={lightboxMedia}
                index={lightboxIndex}
                onOpenChange={(open) => !open && setLightboxMedia(null)}
                onIndexChange={setLightboxIndex}
            />

            {walkthrough.status === 'ready' && walkthrough.videoUrl && (
                <Dialog
                    open={walkthroughOpen}
                    onOpenChange={setWalkthroughOpen}
                    title="Walkthrough"
                    hideTitle
                    width="w-[calc(100vw-2rem)]"
                    className="h-[calc(100dvh-2rem)] max-w-none overflow-hidden p-4"
                    data-testid="walkthrough-dialog"
                >
                    <VideoPlayer videoUrl={walkthrough.videoUrl} chapters={walkthrough.chapters ?? []} />
                </Dialog>
            )}
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
