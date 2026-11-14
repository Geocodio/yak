import { router } from '@inertiajs/react';
import { Button, Menu, toast } from '@geocodio/console-ui';
import { ChevronDown, GitPullRequest, Video } from 'lucide-react';
import { useState } from 'react';
import { PrivatePublishGate } from '@/components/threads/share/PrivatePublishGate';
import { store as storeMessage } from '@/routes/threads/messages';
import { store } from '@/routes/threads/pull-request';
import type { ThreadData } from '@/types/threads';

const REQUEST_TEXT = 'Record a walkthrough video and screenshots of this change.';

/** Sends the visible request; Claude receives the full recording prompt from the server. */
function requestWalkthrough(threadId: string, intent: 'record_walkthrough' | 'record_walkthrough_and_open_pr', acknowledgePrivate: boolean) {
    router.post(
        storeMessage.url(threadId),
        { message: REQUEST_TEXT, intent, ...(acknowledgePrivate ? { acknowledged_private_publish: true } : {}) },
        { preserveScroll: true },
    );
}

function recordItem(thread: ThreadData, isRecording: boolean, onSelect: () => void) {
    return { key: 'record', label: isRecording ? 'Recording walkthrough' : 'Record walkthrough', icon: <Video size={14} />, disabled: isRecording || thread.runnerState === 'busy', onSelect, testId: 'record-walkthrough' };
}

/** Asks the server to open the PR; the job's outcome arrives through the thread's `pullRequestOpening`. */
export function requestPullRequest(threadId: string, acknowledgePrivate: boolean, onFinish?: () => void) {
    router.post(store.url(threadId), acknowledgePrivate ? { acknowledged_private_publish: true } : {}, {
        preserveScroll: true,
        onError: () => toast.error('Could not open the pull request', { description: 'Yak could not start it. Try again in a moment.' }),
        onFinish,
    });
}

const chevronClass = 'h-8 w-8 shrink-0 justify-center rounded-l-none rounded-r-[8px] p-0';

/**
 * Opens the pull request for the thread's branch. A private thread asks first, and the
 * acknowledgement is only sent once the person confirms the dialog. The arrow next to the
 * button also records a walkthrough, with or without opening the PR afterwards. The header
 * shows the primary variant; the Changes view's phone bar shows it too.
 */
export function OpenPullRequestButton({ thread, publishWarning, variant = 'primary', className, isRecording = false }: { thread: ThreadData; publishWarning: boolean; variant?: 'primary' | 'secondary'; className?: string; isRecording?: boolean }) {
    const [processing, setProcessing] = useState(false);
    const isOpening = processing || thread.pullRequestOpening?.state === 'opening';

    const submit = () => {
        setProcessing(true);
        toast.info('Opening the pull request', { description: 'Yak is creating it on GitHub. This takes a few seconds.' });
        requestPullRequest(thread.id, publishWarning, () => setProcessing(false));
    };

    if (thread.pullRequestOpening?.state === 'held') {
        return (
            <span className="inline-flex h-8 shrink-0 items-center rounded-[8px] border border-hair bg-panel px-3" data-testid="pull-request-held">
                <span className="inline-flex h-[18px] items-center rounded-pill bg-panel-2 px-2 text-[11.5px] text-muted">Waiting for a co-owner</span>
            </span>
        );
    }

    const triggerClass = variant === 'primary' ? 'border border-l border-accent bg-accent text-accent-ink hover:opacity-90' : 'border border-hair bg-panel text-body hover:bg-panel-2';

    return (
        <PrivatePublishGate required={publishWarning} onConfirm={submit}>
            {(openPullRequest) => (
                <PrivatePublishGate required={publishWarning} onConfirm={() => requestWalkthrough(thread.id, 'record_walkthrough_and_open_pr', publishWarning)}>
                    {(recordAndOpen) => (
                        <span className="inline-flex" data-testid="open-pull-request-split">
                            <Button variant={variant} icon={<GitPullRequest size={13} />} pending={isOpening} disabled={isOpening} onClick={openPullRequest} className={`${className ?? ''} rounded-r-none`} data-testid="open-pull-request">
                                {isOpening ? 'Opening PR…' : 'Open PR'}
                            </Button>
                            <Menu
                                trigger={<ChevronDown size={14} aria-hidden />}
                                aria-label="More ways to open a PR"
                                align="end"
                                items={[
                                    { key: 'open', label: 'Open PR', icon: <GitPullRequest size={14} />, onSelect: openPullRequest, testId: 'open-pull-request-menu-open' },
                                    recordItem(thread, isRecording, () => requestWalkthrough(thread.id, 'record_walkthrough', false)),
                                    { key: 'record-open', label: 'Record walkthrough & open PR', icon: <Video size={14} />, disabled: isRecording || thread.runnerState === 'busy', onSelect: recordAndOpen, testId: 'record-walkthrough-and-open-pull-request' },
                                ]}
                                className={`${chevronClass} ${triggerClass}`}
                                data-testid="open-pull-request-menu"
                            />
                        </span>
                    )}
                </PrivatePublishGate>
            )}
        </PrivatePublishGate>
    );
}

/** The header action once a PR exists: the PR link, with the arrow keeping Record walkthrough, which updates the PR. */
export function ViewPullRequestButton({ thread, pullRequest, isRecording = false }: { thread: ThreadData; pullRequest: { number: number; url: string }; isRecording?: boolean }) {
    return (
        <span className="inline-flex" data-testid="view-pull-request-split">
            <a href={pullRequest.url} target="_blank" rel="noreferrer" className="inline-flex h-8 shrink-0 items-center gap-2 rounded-l-[8px] border border-hair bg-panel px-3 text-[13px] font-medium text-body hover:bg-panel-2" data-testid="thread-header-pr">
                <GitPullRequest size={13} aria-hidden />
                #{pullRequest.number}
                <span className="inline-flex h-[18px] items-center rounded-pill bg-panel-2 px-2 text-[11.5px] text-ok">Open</span>
            </a>
            <Menu
                trigger={<ChevronDown size={14} aria-hidden />}
                aria-label="More pull request actions"
                align="end"
                items={[recordItem(thread, isRecording, () => requestWalkthrough(thread.id, 'record_walkthrough', false))]}
                className={`${chevronClass} border border-l-0 border-hair bg-panel text-muted hover:bg-panel-2 hover:text-body`}
                data-testid="view-pull-request-menu"
            />
        </span>
    );
}
