export function DayDivider({ iso }: { iso: string }) {
    return (
        <div className="flex items-center gap-3 text-[11px] text-faint" data-testid="thread-day-divider">
            <span className="h-px flex-1 bg-hair" />
            {new Date(iso).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}
            <span className="h-px flex-1 bg-hair" />
        </div>
    );
}
