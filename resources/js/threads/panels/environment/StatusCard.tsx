import { RetryRestoreButton } from '@/components/threads/conversation/RestoreProgress';
import { rebuildProgress } from '@/threads/threadStatus';
import type { ThreadComposer, ThreadData, ThreadNotice } from '@/types/threads';

function Card({ title, body, children }: { title: string; body: string; children?: React.ReactNode }) {
    return (
        <section className="rounded-card bg-panel-2 p-3" data-testid="environment-status-card">
            <p className="text-[13px] font-medium text-body">{title}</p>
            <p className="mt-1 text-[12px] text-muted">{body}</p>
            {children}
        </section>
    );
}

export function StatusCard({ thread, notice, composerState }: { thread: ThreadData; notice: ThreadNotice | null | undefined; composerState: ThreadComposer['state'] | undefined }) {
    const progress = notice ? rebuildProgress(notice) : null;

    if (notice && progress !== null) {
        const done = notice.restoreSteps.filter((step) => step.status === 'done').length;
        const bar = (colour: string) => (
            <div className="mt-2 h-1.5 overflow-hidden rounded-pill bg-hair">
                <div className={`h-full rounded-pill ${colour}`} style={{ width: `${progress.total === 0 ? 0 : (done / progress.total) * 100}%` }} />
            </div>
        );

        if (notice.kind === 'restore_failed') {
            return (
                <Card title={`Rebuild stopped at step ${progress.current}`} body={progress.label ? `${progress.label}.` : 'A rebuild step failed.'}>
                    {bar('bg-fail')}
                    {composerState !== 'readonly' && <RetryRestoreButton threadId={thread.id} testId="sidebar-restore-retry" className="mt-3">Retry from this step</RetryRestoreButton>}
                </Card>
            );
        }

        return (
            <Card title={`Rebuilding, step ${progress.current} of ${progress.total}`} body={progress.label ? `${progress.label}.` : 'Starting.'}>
                {bar('bg-brand')}
            </Card>
        );
    }

    if (notice?.kind === 'waiting_for_account') {
        return <Card title="Waiting for the account" body="The sandbox starts once a turn on this account finishes." />;
    }

    if (composerState === 'working') {
        return <Card title="Sandbox running" body="Claude is working in it now." />;
    }

    return null;
}
