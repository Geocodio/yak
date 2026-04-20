import { spawnSync } from 'node:child_process';
import { emitAutoEvents, navigationMayHaveOccurred } from '../lib/autoEvents.ts';
import { captureCurrentUrl } from '../lib/currentUrl.ts';
import { appendEvent } from '../lib/storyboard.ts';
import { readSession } from '../lib/session.ts';

export function runPassthrough(opts: {
  argv: string[];
  agentBrowserPath: string;
  artifactsDir: string;
}): number {
  const hasSession = readSession(opts.artifactsDir) !== null;
  const mayNavigate = hasSession && navigationMayHaveOccurred(opts.argv);
  const urlBefore = mayNavigate ? captureCurrentUrl(opts.agentBrowserPath) : null;

  const result = spawnSync(opts.agentBrowserPath, opts.argv, { stdio: 'inherit' });
  if (result.error) {
    process.stderr.write(
      `yak-browser: failed to invoke agent-browser at ${opts.agentBrowserPath}: ${result.error.message}\n`,
    );
    return 127;
  }

  if (result.status === 0 && hasSession) {
    emitAutoEvents(opts.artifactsDir, opts.argv);
    if (mayNavigate) {
      const urlAfter = captureCurrentUrl(opts.agentBrowserPath);
      if (urlAfter && urlAfter !== urlBefore) {
        appendEvent(opts.artifactsDir, { type: 'navigate', url: urlAfter });
      }
    }
  }
  return result.status ?? 1;
}
