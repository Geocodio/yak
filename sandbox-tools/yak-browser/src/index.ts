import { runPassthrough } from './commands/passthrough.ts';
import { runScript } from './commands/script.ts';
import { runAssets } from './commands/assets.ts';
import { runShoot } from './commands/shoot.ts';
import { getFlag, pickPositional } from './lib/argv.ts';

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

  // Everything else → passthrough.
  return runPassthrough({ argv, agentBrowserPath: AGENT_BROWSER, artifactsDir: ARTIFACTS_DIR });
}

const code = await main(process.argv.slice(2));
process.exit(code);
