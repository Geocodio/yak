import type { Page } from 'playwright-core';

const CURSOR_GLIDE_MS = 650;
const SCROLL_MIN_MS = 500;
const SCROLL_MAX_MS = 1400;

/**
 * Injected once per document. Renders a synthetic pointer (Playwright's real
 * cursor is not captured by the screencast) plus a click pulse, and forces
 * scroll-behavior to auto so our own eased scrolling is the only motion.
 */
export const CURSOR_INIT_SCRIPT = `(() => {
  if (window.__yakCursor) return;
  const cursor = document.createElement('div');
  cursor.id = '__yak_cursor';
  cursor.innerHTML = '<svg width="28" height="28" viewBox="0 0 28 28"><path d="M4 2 L4 22 L9.5 17 L13.5 26 L17 24.5 L13 15.5 L20.5 15.5 Z" fill="#111" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
  Object.assign(cursor.style, {
    position: 'fixed', left: '0px', top: '0px', zIndex: '2147483647', pointerEvents: 'none',
    transform: 'translate(' + Math.round(window.innerWidth * 0.45) + 'px,' + Math.round(window.innerHeight * 0.55) + 'px)',
    transition: 'transform ${CURSOR_GLIDE_MS}ms cubic-bezier(.2,.7,.2,1)',
    filter: 'drop-shadow(0 2px 3px rgba(0,0,0,.35))',
  });
  document.documentElement.appendChild(cursor);
  window.__yakCursor = cursor;
  window.__yakMoveCursor = (x, y) => { cursor.style.transform = 'translate(' + (x - 4) + 'px,' + (y - 2) + 'px)'; };
  window.__yakClickPulse = () => {
    const rect = cursor.getBoundingClientRect();
    const pulse = document.createElement('div');
    Object.assign(pulse.style, {
      position: 'fixed', left: (rect.left + 4 - 18) + 'px', top: (rect.top + 2 - 18) + 'px',
      width: '36px', height: '36px', borderRadius: '50%', border: '3px solid rgba(196,116,74,.9)',
      zIndex: '2147483646', pointerEvents: 'none', animation: '__yakPulse 500ms ease-out forwards',
    });
    document.documentElement.appendChild(pulse);
    setTimeout(() => pulse.remove(), 600);
  };
  const style = document.createElement('style');
  style.textContent = '@keyframes __yakPulse{from{transform:scale(.4);opacity:1}to{transform:scale(1.6);opacity:0}} html{scroll-behavior:auto !important}';
  document.head.appendChild(style);
})();`;

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

export async function ensureCursor(page: Page): Promise<void> {
  await page.evaluate(CURSOR_INIT_SCRIPT);
}

export async function hideCursor(page: Page): Promise<void> {
  await page.evaluate(() => {
    const cursor = document.getElementById('__yak_cursor');
    if (cursor) cursor.style.display = 'none';
  });
}

export async function showCursor(page: Page): Promise<void> {
  await page.evaluate(() => {
    const cursor = document.getElementById('__yak_cursor');
    if (cursor) cursor.style.display = 'block';
  });
}

/** rAF-driven eased scroll so the recording shows motion, not a jump. */
export async function smoothScrollTo(page: Page, selector: string): Promise<void> {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: 'attached', timeout: 10_000 });
  await locator.evaluate(
    (element, [minMs, maxMs]) =>
      new Promise<void>((resolve) => {
        // Named function bindings here get rewritten by esbuild's keepNames
        // helper (tsx enables it unconditionally), which injects calls to a
        // module-scoped `__name` that does not exist once this closure is
        // serialised and re-evaluated inside the page. Object-property
        // assignment avoids that rewrite, so the recursive step lives there.
        const anim: { ease?: (t: number) => number; step?: (now: number) => void } = {};
        const rect = element.getBoundingClientRect();
        const target = Math.max(0, window.scrollY + rect.top - 96);
        const start = window.scrollY;
        const distance = target - start;
        if (Math.abs(distance) < 2) {
          resolve();
          return;
        }
        const duration = Math.min(maxMs, Math.max(minMs, Math.abs(distance) * 0.9));
        const t0 = performance.now();
        anim.ease = (t: number): number => 1 - Math.pow(1 - t, 3);
        anim.step = (now: number): void => {
          const progress = Math.min(1, (now - t0) / duration);
          window.scrollTo(0, start + distance * anim.ease!(progress));
          if (progress < 1) requestAnimationFrame(anim.step!);
          else resolve();
        };
        requestAnimationFrame(anim.step);
      }),
    [SCROLL_MIN_MS, SCROLL_MAX_MS],
  );
}

/** Glide the synthetic cursor onto an element and wait for the transition. */
export async function moveCursorTo(page: Page, selector: string): Promise<void> {
  const locator = page.locator(selector).first();
  const box = await locator.boundingBox();
  if (box === null) return;
  await page.evaluate(
    ([x, y]) => (window as unknown as { __yakMoveCursor: (x: number, y: number) => void }).__yakMoveCursor(x, y),
    [box.x + Math.min(box.width * 0.35, 220), box.y + Math.min(box.height / 2, 24)],
  );
  await sleep(CURSOR_GLIDE_MS + 50);
}

export async function clickPulse(page: Page): Promise<void> {
  await page.evaluate(() => (window as unknown as { __yakClickPulse: () => void }).__yakClickPulse());
  await sleep(250);
}
