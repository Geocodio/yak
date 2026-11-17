import { Button } from '@geocodio/console-ui';

/** Sticky bar for a section's text fields; renders nothing while the section is clean. */
export function SectionSaveBar({ isDirty, processing, onSave, onDiscard, label = 'Save changes', disabled = false }: {
    isDirty: boolean;
    processing: boolean;
    /** Holds Save back while the `.yak/` config is still loading, because locked fields are unknown until then. */
    disabled?: boolean;
    onSave: () => void;
    onDiscard: () => void;
    label?: string;
}) {
    if (!isDirty) {
        return null;
    }

    return (
        <div
            data-testid="section-save-bar"
            className="sticky bottom-0 z-10 -mx-6 mt-6 flex flex-wrap items-center gap-3 border-t border-hair bg-panel px-6 py-3 max-lg:bottom-[calc(5.75rem+env(safe-area-inset-bottom))] max-lg:pb-3"
        >
            <span className="mr-auto flex items-center gap-2 text-[13px] text-muted">
                <span className="size-2 rounded-full bg-warn" aria-hidden />
                Unsaved changes in this section
            </span>
            <Button onClick={onDiscard} disabled={processing} data-testid="section-discard">Discard</Button>
            <Button variant="primary" onClick={onSave} disabled={processing || disabled} data-testid="section-save">{processing ? 'Saving…' : label}</Button>
        </div>
    );
}
