import { readFileSync } from 'node:fs';
import { lintScriptStatic } from '../v3/lint.ts';
import { dryRunSelectors } from '../v3/dryRun.ts';
import type { Script } from '../v3/types.ts';

const REVIEW_CHECKLIST = `
Editor pass — answer these three before shooting:
  1. Does the intro say what changed, in the reviewer's language?
  2. Does every shot show something the diff touched?
  3. Would a reviewer know where to look in each shot?
`;

export type ScriptCommandOptions = {
  scriptPath: string;
  base?: string;
  review?: boolean;
  projectRoot?: string;
};

/**
 * Validate a v3 script.json. Exit 0 when clean, 2 with one line per error
 * otherwise. Without --base only the static rules run.
 */
export async function runScript(opts: ScriptCommandOptions): Promise<number> {
  let raw: string;
  try {
    raw = readFileSync(opts.scriptPath, 'utf8');
  } catch (error) {
    process.stderr.write(`cannot read ${opts.scriptPath}: ${(error as Error).message}\n`);
    return 2;
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    process.stderr.write(`${opts.scriptPath} is not valid JSON: ${(error as Error).message}\n`);
    return 2;
  }

  const errors = lintScriptStatic(parsed);
  if (errors.length > 0) {
    for (const error of errors) process.stderr.write(`${error}\n`);
    return 2;
  }

  if (opts.review) {
    process.stdout.write(`${JSON.stringify(parsed, null, 2)}\n`);
    process.stdout.write(REVIEW_CHECKLIST);
    return 0;
  }

  if (!opts.base) {
    process.stdout.write('script.json is valid (static rules only — pass --base <url> to dry-run selectors).\n');
    return 0;
  }

  const { preflightFailures, selectorErrors } = await dryRunSelectors(parsed as Script, opts.base, opts.projectRoot);
  if (preflightFailures.length > 0) {
    for (const error of preflightFailures) process.stderr.write(`${error}\n`);
    return 4;
  }
  if (selectorErrors.length > 0) {
    for (const error of selectorErrors) process.stderr.write(`${error}\n`);
    return 2;
  }

  process.stdout.write('script.json is valid (static rules and selector dry run).\n');
  return 0;
}
