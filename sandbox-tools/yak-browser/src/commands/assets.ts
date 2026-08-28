import { runAssetPreflight, formatPreflightFailures } from '../v3/assets.ts';

export type AssetsCommandOptions = {
  argv: string[];
  base?: string;
  projectRoot?: string;
};

/** `yak-browser assets check --base <url>` — exit 0 clean, 4 on any failure. */
export async function runAssets(opts: AssetsCommandOptions): Promise<number> {
  if (opts.argv[0] !== 'check') {
    process.stderr.write('yak-browser assets check --base <url> [--project-root <dir>]\n');
    return 2;
  }
  if (!opts.base) {
    process.stderr.write('yak-browser assets check requires --base <url>\n');
    return 2;
  }
  const failures = await runAssetPreflight({ base: opts.base, projectRoot: opts.projectRoot });
  if (failures.length > 0) {
    process.stderr.write(formatPreflightFailures(failures));
    return 4;
  }
  process.stdout.write('Asset preflight passed.\n');
  return 0;
}
