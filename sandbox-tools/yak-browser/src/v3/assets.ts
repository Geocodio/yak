import type { Page } from 'playwright-core';
import { launchChromium } from './playwright.ts';
import { checkAssetFreshness } from './assetsFs.ts';

const BUNDLER_ERRORS = [
  'Unable to locate file in Vite manifest',
  'ViteException',
  'Cannot find module',
];

const UA_DEFAULT_FONTS = ['Times New Roman', 'Times', 'serif'];

export type PreflightFailure = {
  kind: 'request' | 'stylesheet' | 'bundler' | 'font' | 'stale' | 'unreachable';
  detail: string;
  offenders: string[];
};

type PageProbe = {
  bodyFont: string;
  declaresStylesheet: boolean;
  sameOriginRuleCount: number;
  text: string;
};

async function probe(page: Page): Promise<PageProbe> {
  return page.evaluate(() => {
    let sameOriginRuleCount = 0;
    for (const sheet of Array.from(document.styleSheets)) {
      try {
        const href = (sheet as CSSStyleSheet).href;
        const sameOrigin = href === null || href.startsWith(window.location.origin);
        if (!sameOrigin) continue;
        sameOriginRuleCount += (sheet as CSSStyleSheet).cssRules.length;
      } catch {
        // A cross-origin sheet throws on cssRules; it does not count either way.
      }
    }
    return {
      bodyFont: window.getComputedStyle(document.body).fontFamily,
      declaresStylesheet: document.querySelectorAll('link[rel="stylesheet"], style').length > 0,
      sameOriginRuleCount,
      text: document.body.innerText.slice(0, 4000),
    };
  });
}

/**
 * Spec §5 asset preflight. Returns an empty array when the page and the
 * project tree both look healthy; one entry per detected problem otherwise.
 * Pass an existing `page` to reuse a context (the shoot and the linter dry run
 * both do); otherwise a throwaway browser is launched and closed.
 */
export async function runAssetPreflight(opts: {
  base: string;
  projectRoot?: string;
  page?: Page;
}): Promise<PreflightFailure[]> {
  const failures: PreflightFailure[] = [];

  const staleness = checkAssetFreshness(opts.projectRoot ?? process.cwd());
  if (staleness.status === 'stale') {
    failures.push({ kind: 'stale', detail: staleness.reason, offenders: staleness.staleSources });
  }

  const ownBrowser = opts.page === undefined;
  const browser = ownBrowser ? await launchChromium() : null;
  const page = opts.page ?? (await (await browser!.newContext()).newPage());

  const badRequests: string[] = [];
  const onResponse = (response: { status: () => number; url: () => string; request: () => { resourceType: () => string } }) => {
    const type = response.request().resourceType();
    if (type !== 'stylesheet' && type !== 'script') return;
    if (response.status() >= 400) badRequests.push(`${response.url()} (${response.status()})`);
  };
  const onFailed = (request: { url: () => string; resourceType: () => string; failure: () => { errorText: string } | null }) => {
    const type = request.resourceType();
    if (type !== 'stylesheet' && type !== 'script') return;
    badRequests.push(`${request.url()} (${request.failure()?.errorText ?? 'request failed'})`);
  };

  page.on('response', onResponse as never);
  page.on('requestfailed', onFailed as never);

  try {
    try {
      await page.goto(opts.base, { waitUntil: 'networkidle', timeout: 30_000 });
    } catch (error) {
      failures.push({
        kind: 'unreachable',
        detail: `could not load ${opts.base}: ${(error as Error).message}`,
        offenders: [opts.base],
      });
      return failures;
    }
    const result = await probe(page);

    if (badRequests.length > 0) {
      failures.push({
        kind: 'request',
        detail: 'a stylesheet or script request failed',
        offenders: [...new Set(badRequests)],
      });
    }
    if (result.declaresStylesheet && result.sameOriginRuleCount === 0) {
      failures.push({
        kind: 'stylesheet',
        detail: 'the page declares a stylesheet but no same-origin stylesheet contributed any rules',
        offenders: [opts.base],
      });
    }
    const bundlerError = BUNDLER_ERRORS.find((needle) => result.text.includes(needle));
    if (bundlerError !== undefined) {
      failures.push({ kind: 'bundler', detail: `the page shows a bundler error: ${bundlerError}`, offenders: [opts.base] });
    }
    if (result.declaresStylesheet && UA_DEFAULT_FONTS.some((font) => result.bodyFont.trim().startsWith(font))) {
      failures.push({
        kind: 'font',
        detail: `body font is the UA default (${result.bodyFont}) although the page declares a stylesheet`,
        offenders: [opts.base],
      });
    }
  } finally {
    page.off('response', onResponse as never);
    page.off('requestfailed', onFailed as never);
    if (browser !== null) await browser.close();
  }

  return failures;
}

/** Human-readable exit-4 message, ending with the rebuild hint the agent needs. */
export function formatPreflightFailures(failures: PreflightFailure[]): string {
  const lines = ['Asset preflight failed:'];
  for (const failure of failures) {
    lines.push(`  [${failure.kind}] ${failure.detail}`);
    for (const offender of failure.offenders.slice(0, 10)) lines.push(`    - ${offender}`);
    if (failure.offenders.length > 10) lines.push(`    - … and ${failure.offenders.length - 10} more`);
  }
  lines.push('');
  lines.push('Rebuild the frontend assets per this repository\'s setup notes (or start the dev server), then re-run.');
  return `${lines.join('\n')}\n`;
}
