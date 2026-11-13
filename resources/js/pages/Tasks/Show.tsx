import { Head, usePoll } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import { cn, Dialog } from '@geocodio/console-ui';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { StepDetailContext } from '@/components/activity/StepDetailContext';
import { EarlyWarningNotice } from '@/components/tasks/EarlyWarningNotice';
import { CoOwnerPanel } from '@/components/tasks/CoOwnerPanel';
import { QuestionsForm } from '@/components/tasks/QuestionsForm';
import { Composer } from '@/components/tasks/Composer';
import { DebugDetails } from '@/components/tasks/DebugDetails';
import { DeploymentCard } from '@/components/tasks/DeploymentCard';
import { HeaderBand } from '@/components/tasks/HeaderBand';
import { LogEntrySheet } from '@/components/tasks/LogEntrySheet';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import { TaskSummary } from '@/components/tasks/TaskSummary';
import { TaskTabs, type TaskTab } from '@/components/tasks/TaskTabs';
import { ThreadEntry } from '@/components/tasks/ThreadEntry';
import type { YakBubbleContext } from '@/components/tasks/YakBubble';
import { TranscriptOverlay } from '@/components/tasks/TranscriptOverlay';
import { VideoPlayer } from '@/components/tasks/VideoPlayer';
import { WalkthroughCard } from '@/components/tasks/WalkthroughCard';
import { POLLED_PROPS, useActivityRows } from '@/components/tasks/useActivityRows';
import { useStepContent } from '@/components/tasks/useStepContent';
import { replaceTaskQuery } from '@/lib/taskQuery';
import { useStickToBottom } from '@/components/threads/conversation/useStickToBottom';
import { useMediaQuery } from '@/lib/useMediaQuery';
import type { CoOwnerData, EarlyWarningData } from '@/types/coOwner';
import type { PageProps } from '@/types/shared';
import type {
    ActionsData,
    ActivityData,
    ActivityRow,
    ActivitySummary,
    ComposerData,
    QuestionData,
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
    activitySummary: ActivitySummary;
    activity: ActivityData;
    activityOlder?: ActivityRow[];
    activityTail?: ActivityRow[];
    progress: { steps: ProgressStep[] };
    media: MediaItem[];
    walkthrough: WalkthroughData;
    deployment: DeploymentData;
    findings: FindingsData;
    composer: ComposerData;
    questions: QuestionData[] | null;
    coOwner: CoOwnerData | null;
    earlyWarning: EarlyWarningData | null;
    debug: DebugData;
    actions: ActionsData;
    pollInterval: number;
    transcriptLogId: number | null;
    transcriptEntry?: TranscriptEntry | null;
}>;

export default function Show({
    task,
    thread,
    runs,
    attempts,
    activitySummary,
    activity,
    activityOlder,
    activityTail,
    progress,
    media,
    walkthrough,
    deployment,
    findings,
    composer,
    questions,
    coOwner,
    earlyWarning,
    debug,
    actions,
    pollInterval,
    transcriptLogId,
    transcriptEntry,
}: Props) {
    const latestIdRef = useRef<number | null>(null);
    usePoll(pollInterval, () => ({
        only: [...POLLED_PROPS],
        // `after: 0` asks for the first rows of a run whose window started empty.
        data: { after: latestIdRef.current ?? 0 },
        preserveUrl: true,
    }));

    const [transcriptOpen, setTranscriptOpen] = useState(transcriptLogId !== null);
    const [openLogId, setOpenLogId] = useState<number | null>(transcriptLogId);
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const hasQuestions = composer.state === 'questions' && questions !== null;
    // A repo link in the email auto-reply (`?repo=<slug>`) prefills the
    // answer to the "which repository?" question, if it is one of the offered
    // options. The person still presses Send.
    const [composerFill, setComposerFill] = useState<string | null>(() => {
        const repo = new URLSearchParams(window.location.search).get('repo');
        const isOffered = thread.some((entry) => entry.kind === 'clarification' && entry.options?.includes(repo ?? ''));

        return task.status === 'awaiting_clarification' && repo !== null && isOffered ? repo : null;
    });
    const [walkthroughOpen, setWalkthroughOpen] = useState(false);
    const walkthroughCloseRef = useRef<HTMLButtonElement>(null);
    const [tab, setTab] = useState<TaskTab>(() => {
        const value = new URLSearchParams(window.location.search).get('tab');
        return value === 'details' ? 'details' : 'conversation';
    });
    const isDesktop = useMediaQuery('(min-width: 1024px)');

    const changeTab = (next: TaskTab) => {
        setTab(next);
        replaceTaskQuery({ tab: next === 'conversation' ? undefined : next });
    };

    const isActiveStatus =
        task.status === 'running' ||
        task.status === 'pending' ||
        task.status === 'awaiting_clarification' ||
        task.status === 'awaiting_ci' ||
        task.status === 'awaiting_co_owner' ||
        task.status === 'retrying';
    // A live run is followed to its newest step while the reader is at the bottom; a finished one opens at the top.
    const { scroller, content, hasNew, jumpToNewest } = useStickToBottom(task.id, isActiveStatus);
    const lastYakIndex = thread.reduce((acc, entry, index) => (entry.kind === 'yak' ? index : acc), -1);
    // The activity stream attaches to the bubble of the run on screen; a run
    // that has no bubble of its own (it never produced a reply) falls back to
    // the last one.
    const focusedYakIndex = thread.findIndex((entry) => entry.kind === 'yak' && entry.runId === task.runId);
    const streamIndex = focusedYakIndex >= 0 ? focusedYakIndex : lastYakIndex;
    // A run with activity but no reply of its own yet still shows its stream.
    const orphanStream: ThreadEntryData | null =
        streamIndex === -1 && activitySummary.entries > 0
            ? { kind: 'yak', who: 'Yak', meta: `${activitySummary.entries} steps`, bodyHtml: '', runId: task.runId, live: isActiveStatus, error: null }
            : null;

    useEffect(() => {
        setOpenLogId(transcriptLogId);
        setTranscriptOpen(transcriptLogId !== null);
    }, [transcriptLogId]);

    const openTranscriptAt = (logId: number) => {
        setOpenLogId(logId);
        setTranscriptOpen(true);
        // Updates the deep link without a round trip; TranscriptOverlay fetches
        // the entry itself (`only: ['transcriptEntry']`) if it isn't cached yet.
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

    const currentRunId = task.runId;
    const runKey = `${currentRunId}:${task.attempt}`;
    const activityRows = useActivityRows({ runKey, activity, activityOlder, activityTail });
    latestIdRef.current = activityRows.latestId;

    const stepContent = useStepContent({ taskId: task.id, runKey, openInFullLog: openTranscriptAt });

    const yakContext = (index: number): YakBubbleContext => ({
        task,
        actions,
        deployment,
        progress: progress.steps,
        findings: index === lastYakIndex ? findings : null,
        onOpenFullLog: openTranscriptCold,
        stream: {
            rows: activityRows.rows,
            summary: activitySummary,
            hasOlder: activityRows.hasOlder,
            loadingOlder: activityRows.loadingOlder,
            onLoadOlder: activityRows.loadOlder,
        },
    });

    const walkthroughCard = (
        <WalkthroughCard taskId={task.id} walkthrough={walkthrough} canRetryRender={actions.canRetryRender} onOpen={() => setWalkthroughOpen(true)} />
    );

    const mediaSection = media.length > 0 && (
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
    );

    const deploymentCard = <DeploymentCard deployment={deployment} />;

    const debugDetailsCard = <DebugDetails debug={debug} />;

    return (
        <StepDetailContext.Provider value={stepContent}>
            <Head title={task.headline} />

            <HeaderBand task={task} actions={actions} deployment={deployment} progress={isActiveStatus ? progress.steps : null} onOpenTranscript={openTranscriptCold} />

            <TaskTabs value={tab} onChange={changeTab} />

            <div className="flex min-h-0 flex-1">
                <div className={cn('flex min-w-0 flex-1 flex-col', tab !== 'conversation' && 'max-lg:hidden')}>
                    <div ref={scroller} className="relative min-h-0 flex-1 overflow-auto">
                        <div ref={content} className="mx-auto max-w-[820px] px-4 py-6 sm:px-8">
                            <TaskSummary task={task} />

                            <div className="mt-6 flex flex-col gap-6">
                                {thread.map((entry, index) => (
                                    <ThreadEntry
                                        key={index}
                                        entry={entry}
                                        yak={yakContext(index)}
                                        focused={index === streamIndex}
                                        onOpenMedia={(items, i) => {
                                            setLightboxMedia(items);
                                            setLightboxIndex(i);
                                        }}
                                        onSelectClarificationOption={(option) => setComposerFill(option)}
                                    />
                                ))}
                                {orphanStream && (
                                    <ThreadEntry entry={orphanStream} yak={yakContext(-1)} focused onOpenMedia={() => undefined} onSelectClarificationOption={() => undefined} />
                                )}
                                {earlyWarning !== null && <EarlyWarningNotice taskId={task.id} warning={earlyWarning} />}
                            </div>

                            {coOwner !== null && (
                                <div className="mt-6 max-lg:pb-[calc(6rem+env(safe-area-inset-bottom))]">
                                    <CoOwnerPanel taskId={task.id} coOwner={coOwner} repoUrl={task.repoUrl} />
                                </div>
                            )}

                            {hasQuestions && questions !== null && (
                                <div className="mt-6 max-lg:pb-[calc(6rem+env(safe-area-inset-bottom))]">
                                    <QuestionsForm key={questions.map((question) => question.id).join(',')} taskId={task.id} questions={questions} />
                                </div>
                            )}
                        </div>
                        {hasNew && isActiveStatus && (
                            <button
                                type="button"
                                onClick={jumpToNewest}
                                data-testid="jump-to-latest"
                                className="sticky bottom-3 mx-auto mb-3 flex w-fit cursor-pointer items-center gap-1.5 rounded-pill border border-hair bg-panel px-3 py-1.5 text-[12px] font-medium text-body shadow-card"
                            >
                                <ArrowDown size={13} aria-hidden />
                                New activity
                            </button>
                        )}
                    </div>

                    {!hasQuestions && composer.state !== 'held' && (
                        <Composer
                            taskId={task.id}
                            composer={composer}
                            fillValue={composerFill}
                            onOpenMedia={(items, i) => {
                                setLightboxMedia(items);
                                setLightboxIndex(i);
                            }}
                        />
                    )}
                </div>

                {!isDesktop && tab === 'details' && (
                    <div className="min-h-0 flex-1 overflow-auto px-4 py-5">
                        <div className="flex flex-col gap-5">
                            {walkthroughCard}
                            {mediaSection}
                            {deploymentCard}
                            {debugDetailsCard}
                        </div>
                    </div>
                )}

                {isDesktop && (
                    <aside
                        className="hidden w-[320px] shrink-0 flex-col gap-5 overflow-auto border-l border-hair bg-sidebar px-4 py-5 lg:flex [&>*]:shrink-0"
                        data-testid="task-sidebar"
                    >
                        {walkthroughCard}
                        {mediaSection}
                        {deploymentCard}
                        {debugDetailsCard}
                    </aside>
                )}
            </div>

            {isDesktop && (
                <TranscriptOverlay
                    open={transcriptOpen}
                    onOpenChange={closeTranscript}
                    rows={activityRows.rows}
                    entry={transcriptEntry}
                    headline={task.headline}
                    runs={runs}
                    currentRunId={currentRunId}
                    attempts={attempts}
                    currentAttempt={task.attempt}
                    selectedLogId={openLogId}
                    onSelectLog={(logId) => {
                        setOpenLogId(logId);
                        replaceTaskQuery({ log: logId });
                    }}
                />
            )}

            {!isDesktop && (
                <LogEntrySheet
                    open={transcriptOpen}
                    onOpenChange={closeTranscript}
                    rows={activityRows.rows}
                    selectedLogId={openLogId}
                    entry={transcriptEntry}
                    onSelectLog={(logId) => {
                        setOpenLogId(logId);
                        replaceTaskQuery({ log: logId });
                    }}
                    runKey={runKey}
                />
            )}

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
                    width="w-[calc(100vw-2rem)] lg:w-auto"
                    className="max-w-[calc(100vw-2rem)] overflow-hidden p-0"
                    initialFocus={walkthroughCloseRef}
                    data-testid="walkthrough-dialog"
                >
                    <VideoPlayer
                        videoUrl={walkthrough.videoUrl}
                        chapters={walkthrough.chapters ?? []}
                        title={task.headline}
                        onClose={() => setWalkthroughOpen(false)}
                        closeRef={walkthroughCloseRef}
                    />
                </Dialog>
            )}
        </StepDetailContext.Provider>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
