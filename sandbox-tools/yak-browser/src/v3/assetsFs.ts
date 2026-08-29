import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const MANIFEST_CANDIDATES = [
  join('public', 'build', 'manifest.json'),
  join('public', 'build', '.vite', 'manifest.json'),
  join('public', 'mix-manifest.json'),
];

const OUTPUT_ROOTS = [join('public', 'build'), 'dist', 'build', join('public', 'css'), join('public', 'js')];

const SOURCE_EXTENSIONS = [
  '.css', '.scss', '.sass', '.less', '.js', '.ts', '.jsx', '.tsx', '.vue', '.svelte', '.postcss',
];

const SOURCE_FILENAMES = /^(tailwind\.config\.[a-z]+|vite\.config\.[a-z]+|package\.json)$/;

const IGNORED_DIRS = new Set(['node_modules', 'vendor', '.git', '.yak-artifacts', 'storage', 'bootstrap']);

export type StalenessResult = {
  status: 'fresh' | 'stale' | 'skipped';
  reason: string;
  staleSources: string[];
  oldestOutput: string | null;
};

function walk(dir: string, onFile: (path: string) => void, skipDirs: Set<string>): void {
  let entries;
  try {
    entries = readdirSync(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const entry of entries) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (IGNORED_DIRS.has(entry.name) || skipDirs.has(path)) continue;
      walk(path, onFile, skipDirs);
    } else if (entry.isFile()) {
      onFile(path);
    }
  }
}

function mtime(path: string): number | null {
  try {
    return statSync(path).mtimeMs;
  } catch {
    return null;
  }
}

function collectOutputs(projectRoot: string): { files: string[]; roots: string[] } {
  const roots: string[] = [];
  const files: string[] = [];

  for (const candidate of MANIFEST_CANDIDATES) {
    const manifestPath = join(projectRoot, candidate);
    if (!existsSync(manifestPath)) continue;
    files.push(manifestPath);
    try {
      const manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as Record<string, unknown>;
      const base = candidate.includes(join('build', '.vite'))
        ? join(projectRoot, 'public', 'build')
        : join(projectRoot, candidate.split(sep).slice(0, -1).join(sep));
      for (const value of Object.values(manifest)) {
        const file = typeof value === 'string' ? value : (value as { file?: string })?.file;
        if (typeof file !== 'string') continue;
        const resolved = file.startsWith('/') ? join(projectRoot, 'public', file) : join(base, file);
        if (existsSync(resolved)) files.push(resolved);
      }
    } catch {
      // A manifest we cannot parse is still an output; its own mtime counts.
    }
  }

  for (const outputRoot of OUTPUT_ROOTS) {
    const path = join(projectRoot, outputRoot);
    if (!existsSync(path)) continue;
    roots.push(path);
    walk(path, (file) => files.push(file), new Set());
  }

  return { files: [...new Set(files)], roots };
}

/**
 * Spec §5 freshness check, modelled on make: the OLDEST build output must be
 * newer than the NEWEST frontend source. No build output at all means a dev
 * server is serving source and the check is skipped.
 */
export function checkAssetFreshness(projectRoot: string): StalenessResult {
  const { files: outputs, roots } = collectOutputs(projectRoot);
  if (outputs.length === 0) {
    return {
      status: 'skipped',
      reason: 'no build output found (a dev server is probably serving source)',
      staleSources: [],
      oldestOutput: null,
    };
  }

  let oldestOutput: string | null = null;
  let oldestMs = Number.POSITIVE_INFINITY;
  for (const output of outputs) {
    const ms = mtime(output);
    if (ms === null) continue;
    if (ms < oldestMs) {
      oldestMs = ms;
      oldestOutput = output;
    }
  }
  if (oldestOutput === null) {
    return { status: 'skipped', reason: 'build outputs are unreadable', staleSources: [], oldestOutput: null };
  }

  const skipDirs = new Set(roots);
  const staleSources: string[] = [];
  walk(
    projectRoot,
    (file) => {
      const name = file.split(sep).pop() ?? '';
      const isSource =
        SOURCE_EXTENSIONS.some((ext) => name.endsWith(ext)) || SOURCE_FILENAMES.test(name);
      if (!isSource) return;
      const ms = mtime(file);
      if (ms !== null && ms > oldestMs) staleSources.push(relative(projectRoot, file));
    },
    skipDirs,
  );

  if (staleSources.length > 0) {
    return {
      status: 'stale',
      reason: `${staleSources.length} frontend source file(s) are newer than the build output ${relative(projectRoot, oldestOutput)}`,
      staleSources: staleSources.sort().slice(0, 20),
      oldestOutput: relative(projectRoot, oldestOutput),
    };
  }

  return { status: 'fresh', reason: 'build output is newer than every frontend source', staleSources: [], oldestOutput: relative(projectRoot, oldestOutput) };
}
