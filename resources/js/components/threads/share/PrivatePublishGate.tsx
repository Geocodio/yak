import { Button, Dialog } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';

const MESSAGE =
    'This thread is private. Opening a pull request publishes its branch and a description written from the private conversation to GitHub.';

/**
 * Wraps the Open PR button. When the thread is not team-visible the click asks
 * for confirmation first; otherwise it goes straight to onConfirm.
 */
export function PrivatePublishGate({
    required,
    onConfirm,
    children,
}: {
    required: boolean;
    onConfirm: () => void;
    children: (open: () => void) => ReactNode;
}) {
    const [isOpen, setIsOpen] = useState(false);

    const confirm = () => {
        setIsOpen(false);
        onConfirm();
    };

    return (
        <>
            {children(required ? () => setIsOpen(true) : onConfirm)}
            {isOpen && (
                <Dialog
                    open
                    onOpenChange={(open) => !open && setIsOpen(false)}
                    title="Open a pull request from a private thread?"
                    data-testid="private-publish-dialog"
                    footer={
                        <div className="flex items-center justify-end gap-2">
                            <Button variant="tertiary" onClick={() => setIsOpen(false)}>Cancel</Button>
                            <Button variant="primary" onClick={confirm} data-testid="private-publish-confirm">Open PR anyway</Button>
                        </div>
                    }
                >
                    <p className="text-[13px]">{MESSAGE}</p>
                </Dialog>
            )}
        </>
    );
}
