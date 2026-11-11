import { router } from '@inertiajs/react';
import { Toggle } from '@geocodio/console-ui';
import { useState } from 'react';
import { Card } from '@/components/accounts/AccountsSection';
import { preferences } from '@/routes/settings/notifications';
import type { PushEventKey } from '@/types/notifications';

const EVENTS: { key: PushEventKey; title: string; body: string }[] = [
    { key: 'needs_you', title: 'Needs you', body: 'Claude asked a question, a thread is blocked, an unattended event is waiting for approval, or a child thread escalated.' },
    { key: 'turn_finished', title: 'Turn finished while you are away', body: 'Claude finished a turn on a thread while you were looking at something else.' },
    { key: 'stack_usage', title: 'Stack usage alerts', body: 'A Claude account you use is close to its limit or has been rate limited.' },
    { key: 'pull_request', title: 'Pull request and CI events', body: 'A review, a failing check or a merge on a thread you own.' },
];

export function EventToggles({ events, isDisabled }: { events: Record<PushEventKey, boolean>; isDisabled: boolean }) {
    const [optimistic, setOptimistic] = useState<Record<PushEventKey, boolean>>(events);

    const change = (key: PushEventKey, value: boolean) => {
        const next = { ...optimistic, [key]: value };
        setOptimistic(next);
        router.put(preferences.url(), next, { preserveScroll: true, onError: () => setOptimistic(events) });
    };

    return (
        <Card className="divide-y divide-hair" data-testid="event-toggles">
            {EVENTS.map((event) => (
                <div key={event.key} className="flex items-start justify-between gap-4 px-4 py-3.5">
                    <div className="min-w-0">
                        <div className="text-[13px] font-medium">{event.title}</div>
                        <p className="mt-0.5 max-w-[520px] text-[12px] leading-snug text-muted">{event.body}</p>
                    </div>
                    <Toggle checked={optimistic[event.key]} onCheckedChange={(value) => change(event.key, value)} label={event.title} disabled={isDisabled} data-testid={`event-toggle-${event.key}`} />
                </div>
            ))}
        </Card>
    );
}
