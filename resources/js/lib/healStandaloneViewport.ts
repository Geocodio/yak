/**
 * iOS home-screen apps have a WebKit bug: once the software keyboard has
 * opened, `100dvh`, `window.innerHeight` and fixed-position overlays stay
 * shorter than the screen after it closes, leaving a blank band under the
 * tab bar and under open sheets. Hiding the app root for one reflow makes
 * WebKit measure the viewport again.
 *
 * `display: none` resets the scroll offset of every element inside the
 * root, so scrolled containers are recorded first and restored after.
 * Browser tabs are unaffected by the bug, so this only runs in standalone
 * mode, and only when the viewport is measurably shorter than the tallest
 * height seen.
 */
const SHRINK_TOLERANCE_PX = 4;
const KEYBOARD_CLOSE_DELAY_MS = 140;

function isStandalone(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

function isTextEntry(target: EventTarget | null): boolean {
    return target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement || (target instanceof HTMLElement && target.isContentEditable);
}

export function healStandaloneViewport(): void {
    if (!isStandalone()) {
        return;
    }

    let tallestHeight = window.innerHeight;
    window.addEventListener('resize', () => {
        tallestHeight = Math.max(tallestHeight, window.innerHeight);
    });
    // Landscape is legitimately shorter, so a rotation starts a new baseline.
    window.screen.orientation?.addEventListener('change', () => {
        tallestHeight = window.innerHeight;
    });

    const heal = () => {
        const root = document.getElementById('app');
        if (root === null || tallestHeight - window.innerHeight <= SHRINK_TOLERANCE_PX) {
            return;
        }
        // A text field still focused means the keyboard is still open.
        if (isTextEntry(document.activeElement)) {
            return;
        }

        const scrolled = Array.from(root.querySelectorAll<HTMLElement>('*'))
            .filter((element) => element.scrollTop > 0 || element.scrollLeft > 0)
            .map((element) => ({ element, top: element.scrollTop, left: element.scrollLeft }));

        root.style.display = 'none';
        void root.offsetHeight;
        root.style.display = '';

        for (const { element, top, left } of scrolled) {
            element.scrollTop = top;
            element.scrollLeft = left;
        }
    };

    document.addEventListener('focusout', (event) => {
        if (isTextEntry(event.target)) {
            window.setTimeout(heal, KEYBOARD_CLOSE_DELAY_MS);
        }
    });
}
