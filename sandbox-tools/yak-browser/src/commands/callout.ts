import { readSession, writeSession } from '../lib/session.ts';
import { appendEvent } from '../lib/storyboard.ts';
import { captureRect } from '../lib/domRect.ts';

const ANCHORS = new Set(['top', 'bottom', 'left', 'right']);

export function runCallout(opts: {
  artifactsDir: string;
  text: string;
  selector: string;
  anchor?: 'top' | 'bottom' | 'left' | 'right';
  agentBrowserPath?: string;
}): number {
  const s = readSession(opts.artifactsDir);
  if (!s) {
    process.stderr.write('yak-browser callout: no active session\n');
    return 3;
  }
  if (!opts.text || opts.text.trim().length === 0) {
    process.stderr.write('yak-browser callout: --text required\n');
    return 7;
  }
  if (!opts.selector || opts.selector.trim().length === 0) {
    process.stderr.write('yak-browser callout: --target required\n');
    return 7;
  }
  if (opts.anchor !== undefined && !ANCHORS.has(opts.anchor)) {
    process.stderr.write(`yak-browser callout: invalid --anchor "${opts.anchor}" (expected top|bottom|left|right)\n`);
    return 7;
  }
  if (s.calloutBudget <= 0) {
    process.stderr.write('yak-browser callout: budget exhausted. Reduce callouts or increase callout_budget in the plan.\n');
    return 8;
  }
  const rect = opts.agentBrowserPath ? captureRect(opts.selector, opts.agentBrowserPath) : null;
  s.calloutBudget -= 1;
  writeSession(opts.artifactsDir, s);
  appendEvent(opts.artifactsDir, {
    type: 'callout',
    text: opts.text,
    selector: opts.selector,
    ...(opts.anchor ? { anchor: opts.anchor } : {}),
    ...(rect ? { rect } : {}),
  });
  return 0;
}
