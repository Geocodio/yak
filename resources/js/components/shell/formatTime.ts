/** Clock time for today, weekday for this week, short date for anything older; empty for null. */
export function formatTime(iso: string | null, now: Date = new Date()): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    if (date.getTime() >= startOfToday) {
        return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }
    if (date.getTime() >= startOfToday - 6 * 86_400_000) {
        return date.toLocaleDateString(undefined, { weekday: 'short' });
    }
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
