import { formatTime } from '@/components/shell/formatTime';
import { ownerLabel } from '@/components/threads/teleport/TeleportNotice';
import type { TeleportData } from '@/types/threads';

export function HeldEventsList({ teleport }: { teleport: TeleportData }) {
    if (teleport.heldEvents.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2" data-testid="held-events">
            <h3 className="text-[13px] font-medium text-body">Held while on {ownerLabel(teleport)}</h3>
            {teleport.heldEvents.map((event) => (
                <article
                    key={event.id}
                    className={`rounded-lg border border-hair p-3 text-[13px] ${event.isTinted ? 'bg-warn-soft' : 'bg-app'}`}
                    data-testid="held-event-card"
                    data-tinted={event.isTinted}
                >
                    <div className="flex items-center justify-between gap-3">
                        <span className="font-medium text-body">{event.title}</span>
                        <span className="text-[12px] text-muted">{formatTime(event.createdAt)}</span>
                    </div>
                    <p className="mt-1 text-muted">{event.summary}</p>
                    {event.pullRequestUrl && (
                        <a href={event.pullRequestUrl} className="mt-1 inline-block text-accent-text hover:underline" target="_blank" rel="noreferrer">
                            Open the pull request
                        </a>
                    )}
                </article>
            ))}
            <p className="text-[12px] text-muted">Yak does not act on these while your computer has the thread.</p>
        </section>
    );
}
