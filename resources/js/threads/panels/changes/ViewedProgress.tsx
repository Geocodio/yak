export function ViewedProgress({ viewed, total }: { viewed: number; total: number }) {
    return (
        <div className="flex shrink-0 items-center gap-2 text-[12px] text-muted" data-testid="changes-viewed-progress">
            <span aria-hidden className="h-1 w-12 overflow-hidden rounded-pill bg-hair">
                <span className="block h-full bg-ok" style={{ width: total === 0 ? '0%' : `${(viewed / total) * 100}%` }} />
            </span>
            <span className="tabular-nums">{viewed} of {total} viewed</span>
        </div>
    );
}
