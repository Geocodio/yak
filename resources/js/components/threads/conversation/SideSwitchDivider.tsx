/** Marks the point in the conversation where the thread moved between Yak and the person's computer. */
export function SideSwitchDivider({ from, to, at }: { from: string; to: string; at: string }) {
    const time = new Date(at).toLocaleTimeString(undefined, { timeStyle: 'short' });

    return (
        <div className="flex items-center gap-3 text-[11px] text-muted" data-testid="side-switch-divider">
            <span className="h-px flex-1 bg-hair" />
            <span>
                Moved from {from} to {to} at {time}
            </span>
            <span className="h-px flex-1 bg-hair" />
        </div>
    );
}
