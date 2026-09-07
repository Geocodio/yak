/**
 * Floating walkthrough preview shown while hovering a task row with a
 * preview GIF. Pointer events are off so the hovered row keeps its hover
 * state while the GIF is shown.
 *
 * The preview stays small on purpose: large enough to read the gist of the
 * walkthrough, small enough to leave most of the task list visible behind it.
 */
export function HoverPreview({ src }: { src: string | null }) {
    if (src === null) {
        return null;
    }

    return (
        <div
            data-testid="task-preview-overlay"
            aria-hidden="true"
            className="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center p-6"
        >
            <img
                src={src}
                alt=""
                style={{ width: 'min(380px, 60vw)', height: 'auto' }}
                className="max-h-[40vh] rounded-card border border-hair bg-panel object-contain shadow-2xl"
                data-testid="task-preview-overlay-image"
            />
        </div>
    );
}
