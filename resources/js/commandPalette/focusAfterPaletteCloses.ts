/** The palette closes first and hands focus back to the page, so a field takes focus on the next frame. */
export function focusAfterPaletteCloses(element: HTMLElement | null | undefined): void {
    requestAnimationFrame(() => element?.focus());
}
