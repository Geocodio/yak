import { useEffect, useState } from 'react';
import { formatSeconds } from '@/components/tasks/groupActivity';

/** The current time, re-read every second so a running step's clock keeps ticking between polls. */
export function useNow(intervalMs = 1000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), intervalMs);

        return () => window.clearInterval(timer);
    }, [intervalMs]);

    return now;
}

/** Seconds since `since`, ticking. */
export function Elapsed({ since, className }: { since: string | null; className?: string }) {
    const now = useNow();
    const started = since !== null ? Date.parse(since) : Number.NaN;

    if (since === null) {
        return null;
    }

    return <span className={className}>{formatSeconds(Number.isNaN(started) ? 0 : Math.max(0, (now - started) / 1000))}</span>;
}
