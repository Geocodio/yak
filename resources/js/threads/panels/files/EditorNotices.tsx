import { usePage } from '@inertiajs/react';
import { Loader2, X } from 'lucide-react';
import type { SharedProps } from '@/types/shared';

/** Shown while Claude's turn is running. Hidden during a conflict, where the banner takes over. */
export function EditorNotices({ working, hasConflict, onDismiss }: { working: boolean; hasConflict: boolean; onDismiss: () => void }) {
    const docsUrl = usePage<SharedProps>().props.docs.baseUrl;

    if (!working || hasConflict) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 border-b border-hair bg-sidebar px-4 py-2 text-[12px] text-muted" data-testid="editor-working-notice">
            <Loader2 size={13} className="shrink-0 animate-spin" />
            <span data-testid="editor-told-line">
                Claude is working in this thread. You can edit any time. Claude gets a note listing the files you save.{' '}
                <a href={docsUrl} target="_blank" rel="noreferrer" className="underline" data-testid="editor-notice-link">
                    How edits and Claude fit together
                </a>
            </span>
            <button type="button" onClick={onDismiss} aria-label="Dismiss" data-testid="editor-notice-dismiss" className="ml-auto shrink-0 text-muted">
                <X size={13} />
            </button>
        </div>
    );
}
