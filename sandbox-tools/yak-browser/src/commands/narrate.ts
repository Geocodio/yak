import { readSession } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';

export function runNarrate(opts: { artifactsDir: string; text: string }): number {
  if (!readSession(opts.artifactsDir)) {
    process.stderr.write('yak-browser narrate: no active session\n');
    return 3;
  }
  if (!opts.text || opts.text.trim().length === 0) {
    process.stderr.write('yak-browser narrate: text is required\n');
    return 7;
  }
  appendEvent(opts.artifactsDir, { type: 'narrate', text: opts.text });
  return 0;
}
