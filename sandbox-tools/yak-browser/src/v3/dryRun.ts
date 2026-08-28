import { launchChromium } from './playwright.ts';
import { runAction } from './actions.ts';
import { runAssetPreflight, formatPreflightFailures } from './assets.ts';
import type { Script } from './types.ts';

/**
 * Spec §4: execute every shot's actions in a headless page (no recording) and
 * report each selector that does not resolve on the page the shot ends on.
 * Runs the asset preflight first — an unstyled page makes selector results
 * meaningless. Returns one line per problem.
 */
export async function dryRunSelectors(script: Script, base: string, projectRoot?: string): Promise<string[]> {
  const errors: string[] = [];
  const browser = await launchChromium();
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    const preflight = await runAssetPreflight({ base, projectRoot, page });
    if (preflight.length > 0) {
      errors.push(...formatPreflightFailures(preflight).trimEnd().split('\n'));
    }

    for (const shot of script.shots) {
      for (const [index, action] of shot.do.entries()) {
        try {
          await runAction(page, action, base);
        } catch (error) {
          errors.push(`shots[${shot.id}].do[${index}]: ${(error as Error).message}`);
        }
      }
      if (shot.focus !== undefined) {
        const count = await page.locator(shot.focus).count().catch(() => 0);
        if (count === 0) errors.push(`shots[${shot.id}].focus: selector did not resolve: ${shot.focus}`);
      }
    }

    for (const spec of script.screenshots) {
      for (const [index, action] of (spec.do ?? []).entries()) {
        try {
          await runAction(page, action, base);
        } catch (error) {
          errors.push(`screenshots[${spec.id}].do[${index}]: ${(error as Error).message}`);
        }
      }
    }

    await context.close();
  } finally {
    await browser.close();
  }
  return errors;
}
