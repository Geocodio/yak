import { readFileSync } from 'node:fs';
import { lintScriptStatic } from '../v3/lint.ts';
import { shoot, ShotFailedError, PreflightError } from '../v3/shoot.ts';
import type { Script } from '../v3/types.ts';

export type ShootCommandOptions = {
  scriptPath: string;
  base?: string;
  width?: number;
  height?: number;
  only?: string;
  artifactsDir: string;
  projectRoot?: string;
};

/** `yak-browser shoot <file> --base <url>` — 0 ok, 2 bad script, 3 shot failed, 4 preflight. */
export async function runShoot(opts: ShootCommandOptions): Promise<number> {
  if (!opts.base) {
    process.stderr.write('yak-browser shoot <file> --base <url> [--width N --height N] [--only <id>]\n');
    return 2;
  }

  let script: Script;
  try {
    script = JSON.parse(readFileSync(opts.scriptPath, 'utf8')) as Script;
  } catch (error) {
    process.stderr.write(`cannot read ${opts.scriptPath}: ${(error as Error).message}\n`);
    return 2;
  }

  const errors = lintScriptStatic(script);
  if (errors.length > 0) {
    for (const error of errors) process.stderr.write(`${error}\n`);
    return 2;
  }

  try {
    const manifest = await shoot({
      script,
      base: opts.base,
      artifactsDir: opts.artifactsDir,
      width: opts.width ?? 1440,
      height: opts.height ?? 900,
      only: opts.only,
      projectRoot: opts.projectRoot,
    });
    process.stdout.write(`Shot ${manifest.shots.length} shot(s) into ${opts.artifactsDir}.\n`);
    return 0;
  } catch (error) {
    if (error instanceof PreflightError) {
      process.stderr.write(error.message);
      return 4;
    }
    if (error instanceof ShotFailedError) {
      process.stderr.write(`${error.message}\n`);
      process.stderr.write(`Fix the script and re-run: yak-browser shoot ${opts.scriptPath} --base <url> --only ${error.shotId}\n`);
      return 3;
    }
    process.stderr.write(`shoot failed: ${(error as Error).message}\n`);
    return 3;
  }
}
