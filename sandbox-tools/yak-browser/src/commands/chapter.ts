import { readSession, writeSession } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';

export function runChapter(opts: { artifactsDir: string; title: string }): number {
  const s = readSession(opts.artifactsDir);
  if (!s) {
    process.stderr.write('yak-browser chapter: no active session\n');
    return 3;
  }
  const target = opts.title.trim().toLowerCase();
  const idx = s.chapters.findIndex((c) => c.title.trim().toLowerCase() === target);
  if (idx === -1) {
    process.stderr.write(
      `yak-browser chapter: "${opts.title}" is not in the plan. Declared chapters: ${s.chapters.map((c) => c.title).join(', ')}\n`
    );
    return 7;
  }
  if (s.chapters[idx].consumed) {
    process.stderr.write(`yak-browser chapter: "${opts.title}" already opened in this session\n`);
    return 7;
  }
  const firstUnconsumed = s.chapters.findIndex((c) => !c.consumed);
  if (firstUnconsumed !== idx) {
    process.stderr.write(
      `yak-browser chapter: out of order. Next expected chapter is "${s.chapters[firstUnconsumed].title}"\n`
    );
    return 7;
  }
  s.chapters[idx].consumed = true;
  writeSession(opts.artifactsDir, s);
  appendEvent(opts.artifactsDir, { type: 'chapter', title: s.chapters[idx].title });
  return 0;
}
