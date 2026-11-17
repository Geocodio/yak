import { router } from '@inertiajs/react';
import { ConfirmDialog, Dialog } from '@geocodio/console-ui';
import { useState } from 'react';
import { move } from '@/routes/threads';

/** Lists the repositories the request can move to, then asks before moving it. */
export function MoveRepositoryDialog({ threadId, targets, open, onOpenChange }: { threadId: string; targets: string[]; open: boolean; onOpenChange: (open: boolean) => void }) {
    const [target, setTarget] = useState<string | null>(null);
    const [isMoving, setIsMoving] = useState(false);

    const close = () => {
        setTarget(null);
        onOpenChange(false);
    };
    const confirm = () => {
        if (target === null) {
            return;
        }
        setIsMoving(true);
        router.post(move.url(threadId), { repo: target }, { preserveScroll: true, onFinish: () => { setIsMoving(false); close(); } });
    };

    return (
        <>
            <Dialog open={open && target === null} onOpenChange={(next) => !next && close()} title="Move to another repository" description="Pick where Yak should do this work." data-testid="move-dialog">
                {targets.length === 0 ? (
                    <p className="text-[13px] text-muted">No other repository is available.</p>
                ) : (
                    <ul className="m-0 flex max-h-72 list-none flex-col gap-1 overflow-y-auto p-0">
                        {targets.map((repo) => (
                            <li key={repo}>
                                <button type="button" onClick={() => setTarget(repo)} className="w-full cursor-pointer rounded-control px-3 py-2 text-left text-[13px] hover:bg-panel-2" data-testid="move-target">
                                    {repo}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Dialog>
            <ConfirmDialog
                open={open && target !== null}
                onOpenChange={(next) => !next && close()}
                title={`Move this task to ${target ?? ''}?`}
                body="The current sandbox (if any) will be destroyed and the task will restart there."
                confirmLabel="Confirm"
                busy={isMoving}
                onConfirm={confirm}
                confirmTestId="move-confirm"
            />
        </>
    );
}
