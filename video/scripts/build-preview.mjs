#!/usr/bin/env node
/**
 * Bundles the theme page's live preview into a single self-contained IIFE at
 * video/dist/preview.js. The Dockerfile copies it to
 * public/vendor/video-preview.js; the Laravel Vite build is untouched.
 */
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { statSync } from 'node:fs';

const here = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(here, '..');
const outfile = resolve(projectRoot, 'dist/preview.js');

await build({
  entryPoints: [resolve(projectRoot, 'src/preview/entry.tsx')],
  bundle: true,
  format: 'iife',
  platform: 'browser',
  target: ['es2020'],
  minify: true,
  sourcemap: false,
  jsx: 'automatic',
  loader: { '.jpg': 'dataurl', '.png': 'dataurl', '.mp3': 'dataurl', '.webm': 'file' },
  define: { 'process.env.NODE_ENV': '"production"' },
  outfile,
});

const { size } = statSync(outfile);
process.stdout.write(`built video/dist/preview.js (${Math.round(size / 1024)} kB)\n`);
