import { resolveChromiumExecutable } from '../../src/v3/playwright.ts';

/**
 * Integration tests need a real Chromium. On a machine without one, skip
 * rather than fail — the unit tests still cover the logic.
 */
export function hasChromium(): boolean {
  return resolveChromiumExecutable() !== null;
}

export const skipWithoutChromium = hasChromium() ? undefined : 'no Chromium available';
