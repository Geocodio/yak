import { ownerLabel } from '@/components/threads/teleport/TeleportNotice';
import type { TeleportData } from '@/types/threads';

/** What cannot be done from the web while a computer owns the thread. */
export function DisabledActions({ teleport }: { teleport: TeleportData }) {
    const name = ownerLabel(teleport);
    const actions = [
        { label: 'Message Claude', reason: 'Claude is working on the computer. Teleport the thread to Yak to message it here.' },
        { label: 'Push or open PR', reason: 'The branch lives on the computer until the thread comes back.' },
    ];

    return (
        <section className="rounded-lg border border-hair p-4" data-testid="teleport-disabled-actions">
            <h3 className="text-[13px] font-medium text-body">Not available while on {name}</h3>
            <ul className="mt-2 space-y-1.5">
                {actions.map((action) => (
                    <li key={action.label} className="text-[13px] text-muted">
                        <span className="font-medium text-body">{action.label}</span>: {action.reason}
                    </li>
                ))}
            </ul>
        </section>
    );
}
