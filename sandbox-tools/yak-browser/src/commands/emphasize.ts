import { readSession, writeSession } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';

export function runEmphasize(opts: { artifactsDir: string }): number {
  const s = readSession(opts.artifactsDir);
  if (!s) {
    process.stderr.write('yak-browser emphasize: no active session\n');
    return 3;
  }
  if (s.emphasizePending) {
    process.stderr.write(
      'yak-browser emphasize: already pending. Consume the pending emphasize with a click/keystroke before calling again.\n'
    );
    return 7;
  }
  if (s.emphasizeBudget <= 0) {
    process.stderr.write('yak-browser emphasize: budget exhausted\n');
    return 8;
  }
  s.emphasizeBudget -= 1;
  s.emphasizePending = true;
  writeSession(opts.artifactsDir, s);
  appendEvent(opts.artifactsDir, { type: 'emphasize' });
  return 0;
}
