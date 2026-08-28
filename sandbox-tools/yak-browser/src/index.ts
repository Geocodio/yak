import { runPlan } from './commands/plan.ts';
import { runChapter } from './commands/chapter.ts';
import { runNarrate } from './commands/narrate.ts';
import { runNote } from './commands/note.ts';
import { runCallout } from './commands/callout.ts';
import { runEmphasize } from './commands/emphasize.ts';
import { runFastforward } from './commands/fastforward.ts';
import { runPassthrough } from './commands/passthrough.ts';
import { runScript } from './commands/script.ts';
import { runAssets } from './commands/assets.ts';
import { runShoot } from './commands/shoot.ts';
import { startSession, clearSession } from './lib/session.ts';
import { getFlag, pickPositional } from './lib/argv.ts';
import { join } from 'node:path';

const ARTIFACTS_DIR = process.env.YAK_ARTIFACTS_DIR ?? '.yak-artifacts';
const AGENT_BROWSER = process.env.YAK_AGENT_BROWSER_BIN ?? 'agent-browser';

const HELP = `yak-browser — walkthrough capture for Yak sandboxes.

Video v3 commands:
  script <file> [--base <url>] [--review]
        Validate a v3 script.json. Without --base only the static rules run;
        with --base the selectors are dry-run in a headless page and the
        frontend assets are preflighted. --review prints the script with the
        editor checklist. Exit 2 on any lint error, 4 on an asset problem.
  shoot <file> --base <url> [--width 1440] [--height 900] [--only <id>]
        Record one clip per shot with a synthetic cursor and eased scrolling.
        Writes shots/<id>.webm, stills/<id>.png, screenshots/<id>.png and
        manifest.json under YAK_ARTIFACTS_DIR. Exit 3 when a shot fails twice
        (re-run that shot with --only), 4 when the asset preflight fails.
  assets check --base <url> [--project-root <dir>]
        Run the asset preflight on its own. Exit 4 when the page is unstyled,
        a stylesheet or script failed, a bundler error is on the page, or the
        built assets are older than the frontend sources.

Legacy annotation commands (still used by the current prompt):
  plan <file>                          Submit a pre-recording plan.
  chapter "<title>"                    Open a chapter matching the plan.
  narrate "<text>"                     Silent caption strip line.
  callout "<text>" --target=<sel> [--anchor=top|bottom|left|right]
  emphasize                            Zoom on the next click/keystroke.
  fastforward start|stop [--factor=N]  Explicit speed-up segment.
  note "<text>"                        Non-rendered metadata.

Everything else is forwarded verbatim to agent-browser.

Environment:
  YAK_ARTIFACTS_DIR          Defaults to .yak-artifacts
  YAK_AGENT_BROWSER_BIN      Defaults to agent-browser on PATH
  PLAYWRIGHT_BROWSERS_PATH   Defaults to /home/yak/.cache/ms-playwright
  YAK_VIDEO_WIDTH/HEIGHT     Default shoot viewport (1440x900); flags win
`;

async function main(argv: string[]): Promise<number> {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h') {
    process.stdout.write(HELP);
    return 0;
  }
  const [cmd, ...rest] = argv;

  if (cmd === 'script') {
    const scriptPath = pickPositional(rest, ['--base', '--project-root']);
    if (!scriptPath) {
      process.stderr.write('yak-browser script <file> [--base <url>] [--review]\n');
      return 2;
    }
    return runScript({
      scriptPath,
      base: getFlag(rest, '--base'),
      review: rest.includes('--review'),
      projectRoot: getFlag(rest, '--project-root'),
    });
  }

  if (cmd === 'assets') {
    const subcommand = pickPositional(rest, ['--base', '--project-root']);
    return runAssets({
      argv: subcommand ? [subcommand] : [],
      base: getFlag(rest, '--base'),
      projectRoot: getFlag(rest, '--project-root'),
    });
  }

  if (cmd === 'shoot') {
    const scriptPath = pickPositional(rest, ['--base', '--width', '--height', '--only', '--project-root']);
    if (!scriptPath) {
      process.stderr.write('yak-browser shoot <file> --base <url> [--width N --height N] [--only <id>]\n');
      return 2;
    }
    const width = getFlag(rest, '--width') ?? process.env.YAK_VIDEO_WIDTH;
    const height = getFlag(rest, '--height') ?? process.env.YAK_VIDEO_HEIGHT;
    return runShoot({
      scriptPath,
      base: getFlag(rest, '--base'),
      width: width ? Number(width) : undefined,
      height: height ? Number(height) : undefined,
      only: getFlag(rest, '--only'),
      artifactsDir: ARTIFACTS_DIR,
      projectRoot: getFlag(rest, '--project-root'),
    });
  }

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
