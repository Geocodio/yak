import { readSession, writeSession } from './session.ts';
import { appendEvent } from './storyboard.ts';

const SPECIAL_KEYS = new Set([
  'Enter',
  'Escape',
  'Esc',
  'Tab',
  'Backspace',
  'Delete',
  'ArrowUp',
  'ArrowDown',
  'ArrowLeft',
  'ArrowRight',
  'Home',
  'End',
  'PageUp',
  'PageDown',
  'F1',
  'F2',
  'F3',
  'F4',
  'F5',
  'F6',
  'F7',
  'F8',
  'F9',
  'F10',
  'F11',
  'F12',
]);

const MODIFIERS = ['cmd', 'ctrl', 'alt', 'shift', 'meta', 'super'];

function isKeypress(arg: string): boolean {
  const lower = arg.toLowerCase();
  if (MODIFIERS.some((m) => lower.includes(m + '+'))) return true;
  if (SPECIAL_KEYS.has(arg)) return true;
  return false;
}

function findFlag(argv: string[], flag: string): string | undefined {
  const idx = argv.indexOf(flag);
  if (idx === -1 || idx === argv.length - 1) return undefined;
  return argv[idx + 1];
}

/**
 * Commands that can change the page URL. For these we snapshot the URL
 * before the command runs and diff it afterwards to auto-emit a `navigate`
 * storyboard event when the page actually moved — so the URL pill in the
 * rendered video stays in sync even when the agent navigates via a click
 * on an anchor instead of `yak-browser navigate`.
 */
export function navigationMayHaveOccurred(argv: string[]): boolean {
  if (argv.length === 0) return false;
  const cmd = argv[0];
  return (
    cmd === 'click' ||
    cmd === 'left_click' ||
    cmd === 'double_click' ||
    cmd === 'dblclick' ||
    cmd === 'press' ||
    cmd === 'key' ||
    cmd === 'back' ||
    cmd === 'forward' ||
    cmd === 'reload'
  );
}

export function emitAutoEvents(artifactsDir: string, argv: string[]): void {
  if (argv.length === 0) return;
  const s = readSession(artifactsDir);
  if (!s) return;
  const cmd = argv[0];
  if (cmd === 'click' || cmd === 'left_click' || cmd === 'double_click') {
    const xs = findFlag(argv, '--x');
    const ys = findFlag(argv, '--y');
    const x = xs !== undefined ? Number(xs) : 0;
    const y = ys !== undefined ? Number(ys) : 0;
    const selector = findFlag(argv, '--selector');
    appendEvent(artifactsDir, { type: 'click', x, y, ...(selector ? { selector } : {}) });
    if (s.emphasizePending) {
      s.emphasizePending = false;
      writeSession(artifactsDir, s);
    }
    return;
  }
  if (cmd === 'type' || cmd === 'key' || cmd === 'press' || cmd === 'hold_key') {
    const keys = argv[1] ?? '';
    if (isKeypress(keys)) {
      appendEvent(artifactsDir, { type: 'keypress', keys });
      if (s.emphasizePending) {
        s.emphasizePending = false;
        writeSession(artifactsDir, s);
      }
    }
    return;
  }
  if (cmd === 'navigate' || cmd === 'goto' || cmd === 'browser_navigate') {
    const url = argv[1] ?? '';
    if (url) appendEvent(artifactsDir, { type: 'navigate', url });
    return;
  }
}
