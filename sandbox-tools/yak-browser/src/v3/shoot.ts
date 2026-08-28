import { copyFileSync, existsSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { Browser, BrowserContext, Page } from 'playwright-core';
import { launchChromium } from './playwright.ts';
import { runAction } from './actions.ts';
import { ensureCursor, moveCursorTo, hideCursor, showCursor } from './cursor.ts';
import { runAssetPreflight, formatPreflightFailures } from './assets.ts';
import type { Manifest, ManifestScreenshot, ManifestShot, Rect, Script, Shot, ScreenshotSpec } from './types.ts';

const HOLD_MS = 1000;
const SETTLE_AFTER_LOAD_MS = 400;
const STORAGE_STATE_FILE = '.storage-state.json';

export class ShotFailedError extends Error {
  constructor(
    public readonly shotId: string,
    message: string,
  ) {
    super(message);
    this.name = 'ShotFailedError';
  }
}

export class PreflightError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PreflightError';
  }
}

export type ShootOptions = {
  script: Script;
  base: string;
  artifactsDir: string;
  width: number;
  height: number;
  only?: string;
  projectRoot?: string;
  skipPreflight?: boolean;
};

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

function readManifest(artifactsDir: string): Manifest | null {
  const path = join(artifactsDir, 'manifest.json');
  if (!existsSync(path)) return null;
  try {
    return JSON.parse(readFileSync(path, 'utf8')) as Manifest;
  } catch {
    return null;
  }
}

/**
 * Move a file, falling back to copy+unlink when rename cannot cross a
 * filesystem boundary. The recorded clip lives in the OS temp dir and the
 * artifacts dir is frequently a separate mount inside the sandbox container,
 * where a bare renameSync raises EXDEV. `rename` is injectable for the test.
 */
export function moveFile(
  source: string,
  destination: string,
  rename: (from: string, to: string) => void = renameSync,
): void {
  try {
    rename(source, destination);
    return;
  } catch (error) {
    const code = (error as NodeJS.ErrnoException).code;
    if (code !== 'EXDEV' && code !== 'EPERM' && code !== 'EACCES') throw error;
  }
  copyFileSync(source, destination);
  rmSync(source, { force: true });
}

function writeManifest(artifactsDir: string, manifest: Manifest): void {
  writeFileSync(join(artifactsDir, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
}

/** Wait for the network to be quiet and two animation frames to pass. */
async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await page.evaluate(
    () => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))),
  );
  await sleep(SETTLE_AFTER_LOAD_MS);
}

type CarryOver = { url: string; scrollY: number };

type ShotResult = { entry: ManifestShot; carry: CarryOver; screenshots: ManifestScreenshot[] };

async function shootOnce(
  browser: Browser,
  shot: Shot,
  opts: ShootOptions,
  carry: CarryOver | null,
  screenshotsAfter: ScreenshotSpec[],
): Promise<ShotResult> {
  const videoDir = mkdtempSync(join(tmpdir(), `yak-clip-${shot.id}-`));
  const statePath = join(opts.artifactsDir, STORAGE_STATE_FILE);
  const context: BrowserContext = await browser.newContext({
    viewport: { width: opts.width, height: opts.height },
    deviceScaleFactor: 2,
    recordVideo: { dir: videoDir, size: { width: opts.width, height: opts.height } },
    ...(existsSync(statePath) ? { storageState: statePath } : {}),
  });
  const contextStartedAt = Date.now();
  const page = await context.newPage();
  page.on('framenavigated', () => {
    void ensureCursor(page).catch(() => undefined);
  });

  try {
    // Spec §5: the opening navigation happens BEFORE the clock starts, so the
    // page load never lands inside [start, end]. A shot that opens with a
    // `navigate` performs it here; any other shot continues from the previous
    // shot's URL and scroll offset.
    const opensWithNavigate = shot.do[0]?.navigate !== undefined;
    if (opensWithNavigate) {
      await runAction(page, shot.do[0], opts.base);
    } else {
      const url = carry?.url ?? opts.base;
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });
      if (carry !== null) {
        await page.evaluate((y) => window.scrollTo(0, y), carry.scrollY);
      }
    }

    await ensureCursor(page);
    await settle(page);

    // The recorder started with the context; `start` marks where the edit begins.
    const start = (Date.now() - contextStartedAt) / 1000;

    for (const action of shot.do.slice(opensWithNavigate ? 1 : 0)) {
      await runAction(page, action, opts.base);
    }

    let rect: Rect | null = null;
    if (shot.focus !== undefined) {
      await moveCursorTo(page, shot.focus);
      const box = await page.locator(shot.focus).first().boundingBox();
      if (box === null) throw new Error(`focus selector did not resolve: ${shot.focus}`);
      rect = { x: box.x, y: box.y, w: box.width, h: box.height };
    }

    await sleep(HOLD_MS);

    mkdirSync(join(opts.artifactsDir, 'stills'), { recursive: true });
    await page.screenshot({ path: join(opts.artifactsDir, 'stills', `${shot.id}.png`) });

    const screenshots: ManifestScreenshot[] = [];
    if (screenshotsAfter.length > 0) {
      mkdirSync(join(opts.artifactsDir, 'screenshots'), { recursive: true });
      await hideCursor(page);
      for (const spec of screenshotsAfter) {
        const file = `screenshots/${spec.id}.png`;
        await page.screenshot({ path: join(opts.artifactsDir, file) });
        screenshots.push({ id: spec.id, file, caption: spec.caption });
      }
      await showCursor(page);
    }

    const end = (Date.now() - contextStartedAt) / 1000;
    const url = page.url();
    const scrollY = await page.evaluate(() => window.scrollY);

    await context.storageState({ path: statePath });

    const video = page.video();
    await context.close();
    if (video === null) throw new Error('Playwright recorded no video for this shot');
    const recorded = await video.path();
    mkdirSync(join(opts.artifactsDir, 'shots'), { recursive: true });
    const clipRelative = `shots/${shot.id}.webm`;
    moveFile(recorded, join(opts.artifactsDir, clipRelative));

    return {
      entry: { id: shot.id, clip: clipRelative, start, end, rect, url, still: `stills/${shot.id}.png` },
      carry: { url, scrollY },
      screenshots,
    };
  } catch (error) {
    await context.close().catch(() => undefined);
    throw error;
  } finally {
    rmSync(videoDir, { recursive: true, force: true });
  }
}

/** Screenshots with their own `do` list: one throwaway context, no recording. */
async function captureStandaloneScreenshots(
  browser: Browser,
  specs: ScreenshotSpec[],
  opts: ShootOptions,
): Promise<ManifestScreenshot[]> {
  if (specs.length === 0) return [];
  const statePath = join(opts.artifactsDir, STORAGE_STATE_FILE);
  const context = await browser.newContext({
    viewport: { width: opts.width, height: opts.height },
    deviceScaleFactor: 2,
    ...(existsSync(statePath) ? { storageState: statePath } : {}),
  });
  const page = await context.newPage();
  const captured: ManifestScreenshot[] = [];
  try {
    mkdirSync(join(opts.artifactsDir, 'screenshots'), { recursive: true });
    for (const spec of specs) {
      await page.goto(opts.base, { waitUntil: 'networkidle', timeout: 30_000 });
      for (const action of spec.do ?? []) {
        await runAction(page, action, opts.base);
      }
      await settle(page);
      const file = `screenshots/${spec.id}.png`;
      await hideCursor(page);
      await page.screenshot({ path: join(opts.artifactsDir, file) });
      captured.push({ id: spec.id, file, caption: spec.caption });
    }
  } finally {
    await context.close();
  }
  return captured;
}

/**
 * Spec §5. Shoots every shot (or one, with `only`) into
 * <artifactsDir>/shots/<id>.webm + stills/<id>.png and writes manifest.json.
 * Each shot is retried once before ShotFailedError is thrown.
 */
export async function shoot(opts: ShootOptions): Promise<Manifest> {
  mkdirSync(opts.artifactsDir, { recursive: true });
  writeFileSync(join(opts.artifactsDir, 'script.json'), `${JSON.stringify(opts.script, null, 2)}\n`);

  if (opts.skipPreflight !== true) {
    const failures = await runAssetPreflight({ base: opts.base, projectRoot: opts.projectRoot });
    if (failures.length > 0) throw new PreflightError(formatPreflightFailures(failures));
  }

  const existing = readManifest(opts.artifactsDir);
  const targets = opts.only === undefined ? opts.script.shots : opts.script.shots.filter((s) => s.id === opts.only);
  if (targets.length === 0) throw new ShotFailedError(opts.only ?? '', `no shot named "${opts.only}" in the script`);

  const browser = await launchChromium();
  const entries: ManifestShot[] = [];
  const capturedScreenshots: ManifestScreenshot[] = [];
  let carry: CarryOver | null = null;

  try {
    for (const shotSpec of targets) {
      const after = opts.script.screenshots.filter((s) => s.after_shot === shotSpec.id);
      let result: ShotResult | null = null;
      let lastError: Error | null = null;
      for (let attempt = 1; attempt <= 2 && result === null; attempt += 1) {
        try {
          result = await shootOnce(browser, shotSpec, opts, carry, after);
        } catch (error) {
          lastError = error as Error;
          process.stderr.write(`shot "${shotSpec.id}" attempt ${attempt} failed: ${lastError.message}\n`);
        }
      }
      if (result === null) {
        throw new ShotFailedError(shotSpec.id, `shot "${shotSpec.id}" failed twice: ${lastError?.message ?? 'unknown error'}`);
      }
      entries.push(result.entry);
      capturedScreenshots.push(...result.screenshots);
      carry = result.carry;
    }

    const standalone = opts.script.screenshots.filter((s) => s.after_shot === undefined);
    const standaloneToRun = opts.only === undefined ? standalone : [];
    capturedScreenshots.push(...(await captureStandaloneScreenshots(browser, standaloneToRun, opts)));
  } finally {
    await browser.close();
  }

  const shots =
    opts.only === undefined || existing === null
      ? entries
      : existing.shots.map((entry) => entries.find((updated) => updated.id === entry.id) ?? entry);

  const screenshots =
    opts.only === undefined || existing === null
      ? capturedScreenshots
      : [
          ...existing.screenshots.filter((s) => !capturedScreenshots.some((c) => c.id === s.id)),
          ...capturedScreenshots,
        ].sort(
          (a, b) =>
            opts.script.screenshots.findIndex((s) => s.id === a.id) -
            opts.script.screenshots.findIndex((s) => s.id === b.id),
        );

  const manifest: Manifest = {
    version: 3,
    width: opts.width,
    height: opts.height,
    base: opts.base,
    shots,
    screenshots,
  };
  writeManifest(opts.artifactsDir, manifest);
  return manifest;
}
