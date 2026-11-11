import { useEffect, useState } from 'react';

export function formatCountdown(milliseconds: number): string {
    const totalSeconds = Math.max(Math.ceil(milliseconds / 1000), 0);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return minutes > 0 ? `${minutes}m ${String(seconds).padStart(2, '0')}s` : `${seconds}s`;
}

export function useCountdown(resetsAt: string | null): string | null {
    const target = resetsAt === null ? Number.NaN : new Date(resetsAt).getTime();
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (Number.isNaN(target)) {
            return;
        }

        const timer = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(timer);
    }, [target]);

    return Number.isNaN(target) ? null : formatCountdown(target - now);
}
