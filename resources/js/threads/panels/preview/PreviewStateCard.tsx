import { usePage } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { AlertTriangle, Check, FileQuestion, Moon, Monitor, Trash2, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { CopyableAddress } from '@/components/CopyableAddress';
import type { SharedProps } from '@/types/shared';
import type { ThreadPreviewData, ThreadPreviewState } from '@/types/threads';
import { stepStatuses, type StepStatus } from './previewState';

type Props = {
    state: ThreadPreviewState;
    preview: ThreadPreviewData;
    canDrive: boolean;
    canRestore: boolean;
    log: string[];
    error: string | null;
    isRestoring: boolean;
    onRestore: () => void;
};

const STEPS = ['Wake the sandbox', 'Start the dev server', 'Wait for the app to answer'];

function Card({ icon, title, subtitle, children, testId }: { icon: ReactNode; title: string; subtitle?: string; children?: ReactNode; testId: string }) {
    return (
        <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto p-6" data-testid={testId}>
            <div className="flex w-full max-w-md flex-col gap-4 rounded-card border border-hair bg-panel p-5">
                <div className="flex items-start gap-3">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-pill bg-panel-2 text-muted">{icon}</span>
                    <div className="min-w-0">
                        <h2 className="text-[15px] font-semibold text-body">{title}</h2>
                        {subtitle !== undefined && <p className="text-[13px] text-muted">{subtitle}</p>}
                    </div>
                </div>
                {children}
            </div>
        </div>
    );
}

function StepRow({ label, status }: { label: string; status: StepStatus }) {
    return (
        <li className="flex items-center gap-2 text-[13px]" data-testid="preview-step" data-status={status}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                {status === 'done' && <Check size={13} className="text-ok" />}
                {status === 'active' && <span className="h-3 w-3 animate-spin rounded-pill border-2 border-hair border-t-info" aria-hidden="true" />}
                {status === 'pending' && <span className="h-1.5 w-1.5 rounded-pill bg-idle" aria-hidden="true" />}
            </span>
            <span className={cn(status === 'active' ? 'font-medium text-body' : status === 'done' ? 'text-muted' : 'text-faint')}>{label}</span>
        </li>
    );
}

function LogTail({ lines }: { lines: string[] }) {
    if (lines.length === 0) {
        return null;
    }

    return (
        <pre className="max-h-40 overflow-auto rounded-chip border border-hair bg-app p-2 font-mono text-[11.5px] leading-5 text-muted" data-testid="preview-log">
            {lines.join('\n')}
        </pre>
    );
}

export function PreviewStateCard({ state, preview, canDrive, canRestore, log, error, isRestoring, onRestore }: Props) {
    const docsUrl = usePage<SharedProps>().props.docs.baseUrl;

    switch (state) {
        case 'sandbox_asleep':
        case 'waking_sandbox':
        case 'starting_dev_server':
        case 'waiting_for_slot': {
            const statuses = stepStatuses(state);
            const isWaiting = state === 'waiting_for_slot';

            return (
                <Card
                    testId="preview-state-waking"
                    icon={<Moon size={16} />}
                    title={isWaiting ? 'Waiting for a free sandbox' : state === 'starting_dev_server' ? 'Starting the dev server' : 'Waking your preview'}
                    subtitle={isWaiting ? 'All of your awake sandbox slots are in use. This starts as soon as one frees up.' : 'The sandbox was paused to save capacity.'}
                >
                    <ol className="flex flex-col gap-2">
                        {STEPS.map((label, index) => (
                            <StepRow key={label} label={label} status={statuses[index]} />
                        ))}
                    </ol>
                    {state === 'starting_dev_server' && <LogTail lines={log} />}
                    <p className="text-[12px] text-muted">
                        This opens by itself as soon as the app answers, so you can leave the tab. A sandbox you wake for a preview counts toward the {preview.awakeCap} awake sandboxes and your share of{' '}
                        {preview.personAwakeCap}.
                    </p>
                </Card>
            );
        }

        case 'failing':
            return (
                <Card testId="preview-state-failing" icon={<AlertTriangle size={16} className="text-fail" />} title="The preview did not start" subtitle="The dev server started but the app is not answering.">
                    {error !== null && (
                        <p className="rounded-chip bg-fail-soft px-2.5 py-2 text-[12.5px] text-fail" data-testid="preview-error">
                            {error}
                        </p>
                    )}
                    <LogTail lines={log} />
                    <p className="text-[12px] text-muted">Ask Claude to fix the dev server in the conversation. This panel reloads when the app answers.</p>
                </Card>
            );

        case 'no_manifest':
            return (
                <Card testId="preview-state-no-manifest" icon={<FileQuestion size={16} />} title="No preview set up" subtitle="This repository has no .yak/preview.yml, so Yak does not know how to run the app.">
                    <p className="text-[13px] text-muted">Ask Claude to add one, or write it yourself.</p>
                    <a href={docsUrl} target="_blank" rel="noreferrer" className="text-[13px] text-accent-text hover:underline">
                        Preview docs
                    </a>
                </Card>
            );

        case 'invalid_manifest':
            return (
                <Card testId="preview-state-invalid-manifest" icon={<AlertTriangle size={16} className="text-fail" />} title="The preview file is not valid" subtitle="Yak could not read .yak/preview.yml.">
                    {error !== null && (
                        <p className="rounded-chip bg-fail-soft px-2.5 py-2 text-[12.5px] text-fail" data-testid="preview-error">
                            {error}
                        </p>
                    )}
                    <a href={docsUrl} target="_blank" rel="noreferrer" className="text-[13px] text-accent-text hover:underline">
                        Preview docs
                    </a>
                </Card>
            );

        case 'destroyed':
            return (
                <Card testId="preview-state-destroyed" icon={<Trash2 size={16} />} title="The sandbox was removed" subtitle="Sandboxes are removed after a long time without use. The thread history is kept.">
                    {canRestore ? (
                        <div>
                            <Button variant="primary" pending={isRestoring} onClick={onRestore} data-testid="preview-restore">
                                Restore preview
                            </Button>
                        </div>
                    ) : (
                        <p className="text-[12.5px] text-muted" data-testid="preview-read-only">
                            {canDrive ? 'The preview cannot be restored right now.' : 'You can look at this thread but not change it. Someone who can drive it can restore the preview.'}
                        </p>
                    )}
                </Card>
            );

        case 'closed':
            return <Card testId="preview-state-closed" icon={<Lock size={16} />} title="This thread is closed" subtitle="Closed threads do not run a preview." />;

        case 'local_machine':
            return (
                <Card testId="preview-state-local" icon={<Monitor size={16} />} title="This thread runs on a local machine" subtitle="There is no sandbox to preview. Run the app on your computer instead.">
                    {preview.teleportCommand !== null && (
                        <div className="flex flex-col gap-1.5">
                            <span className="text-[12px] text-muted">Bring the thread to your computer:</span>
                            <CopyableAddress address={preview.teleportCommand} />
                        </div>
                    )}
                </Card>
            );

        case 'ready':
            return null;
    }
}
