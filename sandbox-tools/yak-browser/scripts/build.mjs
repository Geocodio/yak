#!/usr/bin/env node
// Builds dist/yak-browser.js: a single-file ESM bundle for Node 20.
//
// playwright-core does not survive a naive esbuild bundle. Three fixes,
// all required (verified against playwright-core 1.62.1):
//   1. chromium-bidi/lib/cjs/* no longer exists in the published package and
//      is only reached from a lazily-initialised BiDi code path we never use.
//   2. esbuild's ESM output turns require() into a throwing stub; playwright
//      needs a real one.
//   3. chokidar (vendored inside playwright-core) does an optional
//      require("fsevents") inside a try/catch. On macOS the native .node
//      binary is installed and esbuild has no loader for it; keeping it
//      external leaves the require to fail at runtime as chokidar expects.
//   4. playwright computes packageRoot = join(__dirname, '..') and reads
//      <packageRoot>/package.json and <packageRoot>/browsers.json. The banner
//      materialises both under os.tmpdir() and points __dirname at it.
import { build } from 'esbuild';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const pwRoot = join(root, 'node_modules', 'playwright-core');
const pwPackageJson = readFileSync(join(pwRoot, 'package.json'), 'utf8');
const pwBrowsersJson = readFileSync(join(pwRoot, 'browsers.json'), 'utf8');
const pwVersion = JSON.parse(pwPackageJson).version;

const banner = `#!/usr/bin/env node
import { createRequire as __yakCreateRequire } from "node:module";
import { fileURLToPath as __yakFileURLToPath } from "node:url";
import { dirname as __yakDirname, join as __yakJoin } from "node:path";
import { mkdirSync as __yakMkdir, writeFileSync as __yakWrite, existsSync as __yakExists } from "node:fs";
import { tmpdir as __yakTmpdir } from "node:os";
const require = __yakCreateRequire(import.meta.url);
const __filename = __yakFileURLToPath(import.meta.url);
const __yakPlaywrightRoot = __yakJoin(__yakTmpdir(), "yak-playwright-${pwVersion}");
__yakMkdir(__yakJoin(__yakPlaywrightRoot, "lib"), { recursive: true });
if (!__yakExists(__yakJoin(__yakPlaywrightRoot, "package.json"))) __yakWrite(__yakJoin(__yakPlaywrightRoot, "package.json"), ${JSON.stringify(pwPackageJson)});
if (!__yakExists(__yakJoin(__yakPlaywrightRoot, "browsers.json"))) __yakWrite(__yakJoin(__yakPlaywrightRoot, "browsers.json"), ${JSON.stringify(pwBrowsersJson)});
const __dirname = __yakJoin(__yakPlaywrightRoot, "lib");
`;

mkdirSync(join(root, 'dist'), { recursive: true });
await build({
  entryPoints: [join(root, 'src', 'index.ts')],
  bundle: true,
  platform: 'node',
  target: 'node20',
  format: 'esm',
  outfile: join(root, 'dist', 'yak-browser.js'),
  external: ['chromium-bidi/*', 'fsevents'],
  banner: { js: banner },
  logLevel: 'info',
});
