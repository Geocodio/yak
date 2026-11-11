import { Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { FileWarning, Lock, MonitorSmartphone, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import type { ThreadData } from '@/types/threads';

export type FileStateKind = 'on_device' | 'destroyed' | 'closed_destroyed' | 'waking' | 'too_large' | 'binary' | 'read_only_viewer';

function Frame({ kind, icon, title, children }: { kind: FileStateKind; icon: ReactNode; title: string; children?: ReactNode }) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-8 text-center" data-testid={`files-state-${kind}`}>
            <div className="flex size-12 items-center justify-center rounded-full bg-sidebar text-muted">{icon}</div>
            <h3 className="text-[14px] font-semibold text-body">{title}</h3>
            <div className="max-w-sm text-[13px] text-muted">{children}</div>
        </div>
    );
}

type Props = { state: FileStateKind; canDrive: boolean; thread: ThreadData; canRestore?: boolean; restoring?: boolean; waitingForSlot?: boolean };

export function FileStates({ state, canDrive, thread, canRestore, restoring, waitingForSlot }: Props) {
    switch (state) {
        case 'on_device':
            return (
                <Frame kind="on_device" icon={<MonitorSmartphone size={20} />} title="This thread has no sandbox on Yak">
                    <p className="mb-3">Its files are on your computer. Open them there, or bring the thread back to Yak.</p>
                    <CommandBlock command={`yak teleport ${thread.name}`} testId="files-teleport-command" />
                </Frame>
            );
        case 'destroyed':
            return (
                <Frame kind="destroyed" icon={<RefreshCw size={20} />} title="The sandbox was removed">
                    <p className="mb-3">Files are not available until the sandbox is restored.</p>
                    {canDrive && (canRestore ?? true) && (
                        <Button variant="secondary" onClick={() => router.post(`/threads/${thread.id}/restore/retry`, {}, { preserveScroll: true })} data-testid="files-restore">
                            Restore sandbox
                        </Button>
                    )}
                </Frame>
            );
        case 'closed_destroyed':
            return (
                <Frame kind="closed_destroyed" icon={<Lock size={20} />} title="This thread is closed">
                    <p>Its sandbox was removed when the thread closed, so its files can no longer be browsed.</p>
                </Frame>
            );
        case 'waking':
            return (
                <Frame kind="waking" icon={<RefreshCw size={20} className="animate-spin" />} title={restoring ? 'Restoring the sandbox' : 'Waking the sandbox'}>
                    <p>{waitingForSlot ? 'Waiting for a free sandbox slot. Files appear as soon as it is awake.' : 'Files appear as soon as it is awake.'}</p>
                    <div className="mt-3 h-1 w-40 overflow-hidden rounded-full bg-sidebar">
                        <div className="h-full w-1/3 animate-pulse rounded-full bg-accent" />
                    </div>
                </Frame>
            );
        case 'too_large':
            return (
                <Frame kind="too_large" icon={<FileWarning size={20} />} title="This file is too large to edit">
                    <p>Files over 1 MB open in the terminal, not here.</p>
                </Frame>
            );
        case 'binary':
            return (
                <Frame kind="binary" icon={<FileWarning size={20} />} title="This file is not text">
                    <p>Binary files are not shown or edited here.</p>
                </Frame>
            );
        case 'read_only_viewer':
            return (
                <p className="border-b border-hair px-4 py-2 text-[12px] text-muted" data-testid="files-state-read_only_viewer">
                    <Lock size={11} className="mr-1 inline" />
                    You can read files in this thread. Only people who can drive it can edit.
                </p>
            );
    }
}
