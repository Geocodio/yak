import { Button, ConfirmDialog } from '@geocodio/console-ui';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';

export function DangerZone({
    slug,
    isActive,
    canDelete,
    deleteBlockedReason,
    onToggleActive,
    onDelete,
    processing,
}: {
    slug: string;
    isActive: boolean;
    canDelete: boolean;
    deleteBlockedReason: string | null;
    onToggleActive: () => void;
    onDelete: () => void;
    processing: boolean;
}) {
    const [confirmOpen, setConfirmOpen] = useState(false);

    return (
        <>
            <div className="flex items-center justify-between rounded-card border border-fail/30 bg-panel px-4 py-3">
                <div>
                    <div className="text-[13px] font-medium">Delete this repository</div>
                    <div className="mt-0.5 text-[12px] text-muted">
                        {canDelete ? 'Permanently delete this repository. This action cannot be undone.' : deleteBlockedReason}
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button onClick={onToggleActive}>{isActive ? 'Deactivate' : 'Activate'}</Button>
                    <Button variant="destructive" disabled={!canDelete} icon={<Trash2 size={13} />} onClick={() => setConfirmOpen(true)} data-testid="delete-repository">
                        Delete
                    </Button>
                </div>
            </div>
            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Delete ${slug}`}
                body="This permanently deletes the repository. Tasks are kept. This cannot be undone."
                confirmLabel="Delete"
                busy={processing}
                onConfirm={() => {
                    onDelete();
                    setConfirmOpen(false);
                }}
            />
        </>
    );
}
