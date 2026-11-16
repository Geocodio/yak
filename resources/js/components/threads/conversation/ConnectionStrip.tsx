import { cn } from '@geocodio/console-ui';
import type { LinkState } from '@/threads/realtime/catchUpRunner';

/** Says for as long as it is true that the page cannot reach Yak, or that live updates run late. */
export function ConnectionStrip({ link }: { link: LinkState }) {
    if (link.kind === 'ok') {
        return null;
    }

    const isOffline = link.kind === 'offline';
    const since = link.since === null ? null : new Date(link.since).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return (
        <div className="shrink-0 px-4 pt-3 sm:px-8" role="status" data-testid="thread-connection-strip" data-kind={link.kind}>
            <div className={cn('mx-auto flex max-w-[820px] items-center gap-2.5 rounded-chip px-3 py-2 text-[12.5px]', isOffline ? 'bg-fail-soft' : 'bg-warn-soft')}>
                <span aria-hidden className={cn('size-[7px] shrink-0 rounded-pill', isOffline ? 'bg-fail' : 'bg-warn')} />
                <span className="text-body">
                    <span className="font-semibold">{isOffline ? 'No connection.' : 'Reconnecting.'}</span>{' '}
                    <span className="text-muted">{isOffline ? `${since === null ? '' : `Last update ${since}. `}Yak keeps trying.` : 'Replies can be a few seconds late.'}</span>
                </span>
            </div>
        </div>
    );
}
