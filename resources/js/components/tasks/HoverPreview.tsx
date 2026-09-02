/**
 * Floating walkthrough preview shown while hovering a task row with a
 * preview GIF. Pointer events are off so the hovered row keeps its hover
 * state while the GIF is shown as large as the viewport allows.
 *
 * Preview GIFs are encoded at 720px wide, so the height is derived from the
 * viewport and the width follows the GIF's own aspect ratio -- this
 * upscales it to fill the screen without distortion.
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
                style={{ height: 'min(85vh, calc(90vw * 9 / 16))', width: 'auto' }}
                className="max-w-[90vw] rounded-card border border-hair bg-panel object-contain shadow-2xl"
                data-testid="task-preview-overlay-image"
            />
        </div>
    );
}
