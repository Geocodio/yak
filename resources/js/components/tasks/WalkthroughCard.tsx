import { Button, StatusPill } from '@geocodio/console-ui';
import { retryRender } from '@/routes/tasks';
import { router } from '@inertiajs/react';
import type { WalkthroughData } from '@/types/tasks';

export function WalkthroughCard({
    taskId,
    walkthrough,
    canRetryRender,
    onOpen,
}: {
    taskId: number;
    walkthrough: WalkthroughData;
    canRetryRender: boolean;
    onOpen: () => void;
}) {
    if (walkthrough.status === 'none') {
        return null;
    }

    return (
        <section data-testid="walkthrough-card">
            <div className="mb-2 flex items-center justify-between">
                <h2 className="text-[11px] font-semibold uppercase tracking-wide text-faint">Walkthrough</h2>
                {walkthrough.status === 'rendering' && <StatusPill tone="warn" label="Rendering" />}
                {walkthrough.status === 'ready' && <StatusPill tone="ok" label="Ready" />}
                {walkthrough.status === 'failed' && <StatusPill tone="fail" label="Failed" />}
            </div>

            {walkthrough.status === 'rendering' ? (
                <div className="rounded-card border border-hair bg-panel p-3 text-[12px] text-muted shadow-card">
                    The cut is being rendered; this card updates on its own.
                </div>
            ) : walkthrough.status === 'failed' ? (
                <div className="rounded-card border border-fail/30 bg-fail-soft/40 p-3 text-[12px] text-fail shadow-card">
                    <p>{walkthrough.error}</p>
                    {canRetryRender && (
                        <Button
                            variant="tertiary"
                            className="mt-2"
                            onClick={() => router.post(retryRender.url(taskId), {}, { preserveScroll: true })}
                            data-testid="retry-render"
                        >
                            Retry render
                        </Button>
                    )}
                </div>
            ) : (
                <button
                    type="button"
                    onClick={onOpen}
                    className="group block w-full overflow-hidden rounded-card border border-hair bg-panel text-left shadow-card hover:border-hair-strong"
                    data-testid="walkthrough-poster"
                >
                    {walkthrough.posterUrl ? (
                        <img src={walkthrough.posterUrl} alt="Walkthrough preview" className="aspect-video w-full object-cover" />
                    ) : (
                        <div className="flex aspect-video items-center justify-center bg-panel-2 text-faint">Walkthrough</div>
                    )}
                    <div className="px-2 py-1.5 text-[11px] text-muted">Watch the walkthrough</div>
                </button>
            )}
        </section>
    );
}
