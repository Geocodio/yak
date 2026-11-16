import { Button } from '@geocodio/console-ui';
import { GitPullRequest, Send } from 'lucide-react';
import { ownerLabel } from '@/components/threads/teleport/TeleportNotice';
import type { TeleportData } from '@/types/threads';

function DisabledAction({ icon, label, reason }: { icon: React.ReactNode; label: string; reason: string }) {
    return (
        <div className="flex items-center justify-between gap-4 border-t border-hair px-4 py-3" data-testid="teleport-disabled-action">
            <div className="min-w-0">
                <p className="text-[13px] font-medium text-body">{label}</p>
                <p className="text-[12px] text-muted">{reason}</p>
            </div>
            <Button variant="secondary" icon={icon} disabled>
                {label}
            </Button>
        </div>
    );
}

/** What cannot be done from the web while a computer owns the thread. */
export function DisabledActions({ teleport }: { teleport: TeleportData }) {
    const owner = (teleport.ownerName ?? 'its owner').split(' ')[0];
    const name = teleport.viewerIsOwner ? ownerLabel(teleport) : `${owner}'s computer`;

    return (
        <section className="rounded-lg border border-hair" data-testid="teleport-disabled-actions">
            <h3 className="px-4 py-3 text-[13px] font-medium text-body">Not available while on {name}</h3>
            <DisabledAction
                icon={<Send size={14} />}
                label="Send message"
                reason={teleport.viewerIsOwner ? 'Claude runs on the computer now. Teleport the thread to Yak to message it here.' : `Claude runs on ${owner}'s computer now.`}
            />
            <DisabledAction
                icon={<GitPullRequest size={14} />}
                label="Push or open PR"
                reason={teleport.viewerIsOwner ? 'Yak only pushes when it owns the thread. Push from the computer, or teleport it back first.' : 'Yak only pushes when it owns the thread.'}
            />
        </section>
    );
}
