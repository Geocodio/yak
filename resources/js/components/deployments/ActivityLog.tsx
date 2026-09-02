import { Button, cn } from '@geocodio/console-ui';
import { useEffect, useRef, useState } from 'react';
import type { DeploymentLogEntry } from '@/types/deployments';

const PHASE_TONE: Record<string, string> = {
    fetch: 'bg-panel-2 text-muted',
    checkout: 'bg-panel-2 text-muted',
    refresh: 'bg-info-soft text-info',
    cold_start: 'bg-accent-soft text-accent-text',
    reclaim_workspace: 'bg-warn-soft text-warn',
    lifecycle: 'bg-warn-soft text-warn',
};

export function ActivityLog({ logs }: { logs: DeploymentLogEntry[] }) {
    const [following, setFollowing] = useState(true);
    const containerRef = useRef<HTMLDivElement>(null);
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (following) {
            bottomRef.current?.scrollIntoView({ block: 'end' });
        }
    }, [logs, following]);

    const onScroll = () => {
        const el = containerRef.current;
        if (!el) {
            return;
        }
        const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        setFollowing(atBottom);
    };

    const jumpToLatest = () => {
        setFollowing(true);
        bottomRef.current?.scrollIntoView({ block: 'end' });
    };

    return (
        <section className="rounded-card border border-hair bg-panel shadow-card">
            <div className="flex items-center justify-between border-b border-hair px-4 py-2.5">
                <h2 className="text-[12px] font-semibold uppercase tracking-wide text-faint">Activity log</h2>
                <div className="flex items-center gap-2 text-[11px] text-faint">
                    <span className="tnum">{logs.length} entries</span>
                    {!following && (
                        <Button variant="link" className="text-[11px]" data-testid="jump-to-latest" onClick={jumpToLatest}>
                            Jump to latest
                        </Button>
                    )}
                    <span className={cn('flex items-center gap-1', following ? 'text-ok' : '')}>
                        <span className={cn('h-1.5 w-1.5 rounded-pill', following ? 'bg-ok' : 'bg-idle')} />
                        {following ? 'Following' : 'Paused'}
                    </span>
                </div>
            </div>
            <div ref={containerRef} onScroll={onScroll} className="max-h-[560px] overflow-auto font-mono text-[11.5px]">
                {logs.length === 0 ? (
                    <div className="p-4 text-[12px] text-muted">No activity yet.</div>
                ) : (
                    logs.map((log, i) => (
                        <div key={i} className={cn('border-b border-hair px-4 py-2 last:border-0', log.error && 'bg-fail-soft/40')}>
                            <div className="flex items-start gap-3">
                                <span className="tnum shrink-0 text-faint">{log.at}</span>
                                {log.phase && <span className={cn('shrink-0 rounded-chip px-1.5 text-[10px] leading-5', PHASE_TONE[log.phase] ?? 'bg-panel-2 text-muted')}>{log.phase}</span>}
                                <span className={cn('min-w-0 flex-1 break-all', log.error && 'text-fail')}>{log.message}</span>
                            </div>
                            {log.output !== '' && <pre className="mt-1.5 ml-[104px] whitespace-pre-wrap rounded-chip bg-panel-2 px-2 py-1.5 text-[11px] text-muted">{log.output}</pre>}
                        </div>
                    ))
                )}
                <div ref={bottomRef} />
            </div>
        </section>
    );
}
