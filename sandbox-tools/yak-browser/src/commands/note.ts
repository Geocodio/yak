import { readSession } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';

export function runNote(opts: { artifactsDir: string; text: string }): number {
  if (!readSession(opts.artifactsDir)) {
    process.stderr.write('yak-browser note: no active session\n');
    return 3;
  }
  if (!opts.text || opts.text.trim().length === 0) {
    process.stderr.write('yak-browser note: text is required\n');
    return 7;
  }
  appendEvent(opts.artifactsDir, { type: 'note', text: opts.text });
  return 0;
}
