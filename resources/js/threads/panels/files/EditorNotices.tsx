import { Loader2 } from 'lucide-react';

/** Shown while Claude's turn is running. Hidden during a conflict, where the banner takes over. */
export function EditorNotices({ working, hasConflict }: { working: boolean; hasConflict: boolean }) {
    if (!working || hasConflict) {
        return null;
    }

    return (
        <div className="flex items-center gap-2 border-b border-hair bg-sidebar px-4 py-2 text-[12px] text-muted" data-testid="editor-working-notice">
            <Loader2 size={13} className="animate-spin" />
            <span className="font-medium text-body">Claude is working</span>
            <span data-testid="editor-told-line">Claude is told about your edits</span>
        </div>
    );
}
