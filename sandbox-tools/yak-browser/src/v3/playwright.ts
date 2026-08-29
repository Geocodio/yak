import { existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { chromium, type Browser } from 'playwright-core';

const DEFAULT_BROWSERS_PATH = '/home/yak/.cache/ms-playwright';

/** Relative binary paths Playwright uses per platform, newest layout first. */
const BINARY_CANDIDATES = [
  'chrome-linux/chrome',
  'chrome-linux/headless_shell',
  'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
  'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
  'chrome-win/chrome.exe',
];

function newestMatchingDir(root: string, prefix: string): string[] {
  if (!existsSync(root)) return [];
  return readdirSync(root)
    .filter((name) => name.startsWith(prefix))
    .map((name) => join(root, name))
    .filter((path) => statSync(path).isDirectory())
    .sort()
    .reverse();
}

/**
 * Find the Chromium the sandbox image installed. Looks under
 * PLAYWRIGHT_BROWSERS_PATH (default /home/yak/.cache/ms-playwright) first,
 * then falls back to playwright-core's own registry. Returns null when
 * nothing usable exists.
 */
export function resolveChromiumExecutable(env: NodeJS.ProcessEnv = process.env): string | null {
  const root = env.PLAYWRIGHT_BROWSERS_PATH ?? DEFAULT_BROWSERS_PATH;
  const dirs = [...newestMatchingDir(root, 'chromium-'), ...newestMatchingDir(root, 'chromium_headless_shell-')];
  for (const dir of dirs) {
    for (const candidate of BINARY_CANDIDATES) {
      const path = join(dir, candidate);
      if (existsSync(path)) return path;
    }
  }
  try {
    const fallback = chromium.executablePath();
    return existsSync(fallback) ? fallback : null;
  } catch {
    return null;
  }
}

/** Launch the resolved Chromium headless. Throws with a rebuild hint when none exists. */
export async function launchChromium(): Promise<Browser> {
  const executablePath = resolveChromiumExecutable();
  if (executablePath === null) {
    throw new Error(
      'no Chromium found. Set PLAYWRIGHT_BROWSERS_PATH or run `npx playwright install chromium`.',
    );
  }
  return chromium.launch({ executablePath, args: ['--force-color-profile=srgb', '--font-render-hinting=none'] });
}
