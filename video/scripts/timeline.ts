/**
 * Print the v3 cut's block timeline as JSON.
 *
 *   npx tsx scripts/timeline.ts --script script.json --manifest manifest.json \
 *     [--voiceover vo.json] [--fps 30]
 *   npx tsx scripts/timeline.ts --theme-defaults
 *
 * Remotion components run in headless Chrome and cannot write files, so the
 * host calls this before rendering to derive chapters.json, the expected
 * duration and caption-fit measurements from exactly the same engine the
 * composition renders with. `--theme-defaults` is the same idea for theming:
 * it hands the host the default palette and the font list so a settings page
 * never has to restate them.
 */
import { readFileSync } from 'node:fs';
import { buildBlocks } from '../src/lib/v3/blocks';
import { captionOverflow } from '../src/lib/v3/captions';
import { buildChapters } from '../src/lib/v3/chapters';
import { supportedFontFamilies } from '../src/lib/v3/fonts';
import { DEFAULT_THEME } from '../src/lib/v3/theme';
import { DEFAULT_FPS } from '../src/lib/v3/types';
import type { Manifest, Script, Voiceover } from '../src/lib/v3/types';

const USAGE =
  'usage: timeline.ts --script <script.json> --manifest <manifest.json> [--voiceover <vo.json>] [--fps <n>]\n' +
  '       timeline.ts --theme-defaults';

function fail(message: string): never {
  process.stderr.write(`${message}\n`);
  process.exit(2);
}

function argument(argv: string[], name: string): string | null {
  const flag = `--${name}`;
  for (let index = 0; index < argv.length; index += 1) {
    if (argv[index] === flag) {
      return argv[index + 1] ?? null;
    }
    if (argv[index].startsWith(`${flag}=`)) {
      return argv[index].slice(flag.length + 1);
    }
  }
  return null;
}

function readJson<T>(filePath: string): T {
  try {
    return JSON.parse(readFileSync(filePath, 'utf8')) as T;
  } catch (error) {
    return fail(`timeline.ts: cannot read ${filePath}: ${(error as Error).message}`);
  }
}

const argv = process.argv.slice(2);

if (argv.includes('--theme-defaults')) {
  process.stdout.write(
    `${JSON.stringify({ theme: DEFAULT_THEME, fonts: supportedFontFamilies() }, null, 2)}\n`,
  );
  process.exit(0);
}

const scriptPath = argument(argv, 'script');
const manifestPath = argument(argv, 'manifest');
if (!scriptPath || !manifestPath) {
  fail(USAGE);
}

const voiceoverPath = argument(argv, 'voiceover');
const fpsArgument = argument(argv, 'fps');
const fps = fpsArgument === null ? DEFAULT_FPS : Number(fpsArgument);
if (!Number.isFinite(fps) || fps <= 0) {
  fail(`timeline.ts: --fps must be a positive number, got "${fpsArgument}"`);
}

const script = readJson<Script>(scriptPath);
const manifest = readJson<Manifest>(manifestPath);
const voiceover: Voiceover = voiceoverPath ? readJson<NonNullable<Voiceover>>(voiceoverPath) : null;

const timeline = buildBlocks({ script, manifest, voiceover, fps });

process.stdout.write(
  `${JSON.stringify(
    {
      fps: timeline.fps,
      width: timeline.width,
      height: timeline.height,
      durationSeconds: timeline.durationSeconds,
      durationInFrames: timeline.durationInFrames,
      blocks: timeline.blocks,
      chapters: buildChapters(timeline),
      captionOverflow: captionOverflow(script),
    },
    null,
    2,
  )}\n`,
);
