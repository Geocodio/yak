import { spawnSync } from 'node:child_process';
import { emitAutoEvents } from '../lib/autoEvents.ts';

export function runPassthrough(opts: {
  argv: string[];
  agentBrowserPath: string;
  artifactsDir: string;
}): number {
  emitAutoEvents(opts.artifactsDir, opts.argv);
  const result = spawnSync(opts.agentBrowserPath, opts.argv, { stdio: 'inherit' });
  if (result.error) {
    process.stderr.write(
      `yak-browser: failed to invoke agent-browser at ${opts.agentBrowserPath}: ${result.error.message}\n`
    );
    return 127;
  }
  return result.status ?? 1;
}
