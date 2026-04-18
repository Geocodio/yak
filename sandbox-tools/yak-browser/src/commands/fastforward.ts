import { readSession, writeSession, nowMs } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';

export function runFastforward(opts: {
  artifactsDir: string;
  action: 'start' | 'stop';
  factor?: number;
}): number {
  const s = readSession(opts.artifactsDir);
  if (!s) {
    process.stderr.write('yak-browser fastforward: no active session\n');
    return 3;
  }
  if (opts.action === 'start') {
    if (s.openFastforward) {
      process.stderr.write('yak-browser fastforward: already in a fastforward segment; stop it first\n');
      return 7;
    }
    const factor = opts.factor ?? 4;
    if (!Number.isFinite(factor) || factor <= 1) {
      process.stderr.write('yak-browser fastforward: --factor must be > 1\n');
      return 7;
    }
    s.openFastforward = { factor, startedAtMs: nowMs() };
    writeSession(opts.artifactsDir, s);
    appendEvent(opts.artifactsDir, { type: 'fastforward', start: true, factor });
    return 0;
  }
  if (!s.openFastforward) {
    process.stderr.write('yak-browser fastforward: no open fastforward segment to stop\n');
    return 7;
  }
  s.openFastforward = null;
  writeSession(opts.artifactsDir, s);
  appendEvent(opts.artifactsDir, { type: 'fastforward', start: false });
  return 0;
}
