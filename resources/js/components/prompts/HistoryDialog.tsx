import { Badge, Button, Dialog } from '@geocodio/console-ui';
import type { PromptVersionRow } from '@/types/prompts';

export function HistoryDialog({
    open,
    onOpenChange,
    label,
    versions,
    onLoad,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    label: string;
    versions: PromptVersionRow[];
    onLoad: (version: PromptVersionRow) => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Version history"
            description={`Restore an earlier edit of ${label}.`}
            footer={<Button onClick={() => onOpenChange(false)}>Close</Button>}
        >
            {versions.length === 0 ? (
                <p className="text-[12.5px] text-muted">No saved versions yet.</p>
            ) : (
                <ul className="divide-y divide-hair">
                    {versions.map((version) => (
                        <li key={version.id} className="flex items-center justify-between py-2 text-[12.5px]" data-testid={`version-row-${version.number}`}>
                            <span>
                                Version {version.number} <span className="text-faint">· {version.createdAgo ?? ''}</span>
                            </span>
                            {version.current ? (
                                <Badge tone="accent">current</Badge>
                            ) : (
                                <Button variant="link" className="text-[12px]" onClick={() => onLoad(version)}>
                                    Load
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Dialog>
    );
}
