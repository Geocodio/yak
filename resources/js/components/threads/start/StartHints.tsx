/** Footer row under the "Runs on" strip: what the chosen mode does, and the input tips. */
export function StartHints({ isResearch }: { isResearch: boolean }) {
    return (
        <div data-testid="start-thread-footer" className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-hair px-[18px] py-2 text-[12px] text-muted">
            <span>{isResearch ? 'Yak investigates and writes a report. No pull request.' : 'Yak makes the change and opens a pull request for you to review.'}</span>
            <span>Type @ to pick a repo. Paste or drop screenshots and files.</span>
        </div>
    );
}
