import { runPlan } from './commands/plan.ts';
import { runChapter } from './commands/chapter.ts';
import { runNarrate } from './commands/narrate.ts';
import { runNote } from './commands/note.ts';
import { runCallout } from './commands/callout.ts';
import { runEmphasize } from './commands/emphasize.ts';
import { runFastforward } from './commands/fastforward.ts';
import { runPassthrough } from './commands/passthrough.ts';
import { startSession, clearSession } from './lib/session.ts';
import { join } from 'node:path';

const ARTIFACTS_DIR = process.env.YAK_ARTIFACTS_DIR ?? '.yak-artifacts';
const AGENT_BROWSER = process.env.YAK_AGENT_BROWSER_BIN ?? 'agent-browser';

function getFlag(argv: string[], flag: string): string | undefined {
  const i = argv.indexOf(flag);
  if (i >= 0 && i < argv.length - 1) return argv[i + 1];
  for (const a of argv) {
    if (a.startsWith(flag + '=')) return a.slice(flag.length + 1);
  }
  return undefined;
}

const HELP = `yak-browser — annotation-aware wrapper around agent-browser.

Annotation commands (augment the storyboard):
  plan <file>                          Submit a pre-recording plan (mandatory).
  chapter "<title>"                    Open a chapter matching the plan.
  narrate "<text>"                     Silent caption strip line.
  callout "<text>" --target=<sel> [--anchor=top|bottom|left|right]
  emphasize                            Zoom on the next click/keystroke.
  fastforward start|stop [--factor=N]  Explicit speed-up segment.
  note "<text>"                        Non-rendered metadata.

Everything else is forwarded verbatim to agent-browser.

Environment:
  YAK_ARTIFACTS_DIR     Defaults to .yak-artifacts
  YAK_AGENT_BROWSER_BIN Defaults to agent-browser on PATH
`;

async function main(argv: string[]): Promise<number> {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h') {
    process.stdout.write(HELP);
    return 0;
  }
  const [cmd, ...rest] = argv;

  // Recording lifecycle — start/stop the session around agent-browser calls.
  if (cmd === 'record' && rest[0] === 'start') {
    const output = rest[1] ?? join(ARTIFACTS_DIR, 'walkthrough.webm');
    startSession(ARTIFACTS_DIR, { storyboardPath: join(ARTIFACTS_DIR, 'storyboard.json') });
    return runPassthrough({
      argv: ['record', 'start', output],
      agentBrowserPath: AGENT_BROWSER,
      artifactsDir: ARTIFACTS_DIR,
    });
  }
  if (cmd === 'record' && rest[0] === 'stop') {
    const code = runPassthrough({
      argv: ['record', 'stop'],
      agentBrowserPath: AGENT_BROWSER,
      artifactsDir: ARTIFACTS_DIR,
    });
    clearSession(ARTIFACTS_DIR);
    return code;
  }

  // Annotation commands.
  if (cmd === 'plan') {
    const planPath = rest[0];
    if (!planPath) {
      process.stderr.write('yak-browser plan <file>\n');
      return 2;
    }
    return runPlan({ artifactsDir: ARTIFACTS_DIR, planPath });
  }
  if (cmd === 'chapter') {
    return runChapter({ artifactsDir: ARTIFACTS_DIR, title: rest.join(' ') });
  }
  if (cmd === 'narrate') {
    return runNarrate({ artifactsDir: ARTIFACTS_DIR, text: rest.join(' ') });
  }
  if (cmd === 'note') {
    return runNote({ artifactsDir: ARTIFACTS_DIR, text: rest.join(' ') });
  }
  if (cmd === 'callout') {
    const text = rest.find((a) => !a.startsWith('--')) ?? '';
    const selector = getFlag(rest, '--target') ?? '';
    const anchor = getFlag(rest, '--anchor') as 'top' | 'bottom' | 'left' | 'right' | undefined;
    return runCallout({ artifactsDir: ARTIFACTS_DIR, text, selector, anchor, agentBrowserPath: AGENT_BROWSER });
  }
  if (cmd === 'emphasize') {
    return runEmphasize({ artifactsDir: ARTIFACTS_DIR });
  }
  if (cmd === 'fastforward') {
    const action = rest[0] as 'start' | 'stop';
    if (action !== 'start' && action !== 'stop') {
      process.stderr.write('yak-browser fastforward start|stop [--factor=N]\n');
      return 2;
    }
    const factorStr = getFlag(rest, '--factor');
    const factor = factorStr ? Number(factorStr) : undefined;
    return runFastforward({ artifactsDir: ARTIFACTS_DIR, action, factor });
  }

  // Everything else → passthrough.
  return runPassthrough({ argv, agentBrowserPath: AGENT_BROWSER, artifactsDir: ARTIFACTS_DIR });
}

const code = await main(process.argv.slice(2));
process.exit(code);
