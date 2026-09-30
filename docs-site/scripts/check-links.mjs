#!/usr/bin/env node
/**
 * Check links in the built docs site (dist/).
 *
 * Fails when:
 *  - a link between docs pages does not resolve to a built page,
 *  - a #anchor (in-page or cross-page) has no matching id in the target page,
 *  - an anchor path in ../config/docs.php (the app's doc links) does not resolve.
 *
 * Dependency-free. Run after `npm run build`.
 */

import { readdirSync, readFileSync, existsSync, statSync } from 'node:fs';
import { join, dirname, resolve, posix } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DIST = resolve(__dirname, '../dist');
const DOCS_CONFIG = resolve(__dirname, '../../config/docs.php');
const BASE = '/yak';

if (!existsSync(DIST)) {
  console.error('dist/ not found. Run `npm run build` first.');
  process.exit(1);
}

function htmlFiles(directory) {
  const found = [];
  for (const entry of readdirSync(directory)) {
    const full = join(directory, entry);
    if (statSync(full).isDirectory()) {
      if (entry === 'pagefind' || entry === '_astro') continue;
      found.push(...htmlFiles(full));
    } else if (entry.endsWith('.html')) {
      found.push(full);
    }
  }
  return found;
}

const idsByPage = new Map();
const htmlByPage = new Map();
for (const file of htmlFiles(DIST)) {
  const html = readFileSync(file, 'utf8');
  const pagePath = '/' + file.slice(DIST.length + 1).replace(/index\.html$/, '');
  idsByPage.set(pagePath, new Set([...html.matchAll(/\sid=["']([^"']+)["']/g)].map((m) => m[1])));
  htmlByPage.set(pagePath, html);
}

/** Resolve a site path (without base) to a built page key, or null. */
function resolvePage(sitePath) {
  const candidates = [sitePath, sitePath.replace(/\/?$/, '/'), sitePath.replace(/\.html$/, '') + '/'];
  for (const candidate of candidates) {
    if (idsByPage.has(candidate)) return candidate;
  }
  return null;
}

const problems = [];

function checkTarget(source, targetPath, anchor) {
  const page = resolvePage(targetPath);
  if (page === null) {
    // Not a built page: accept static assets that exist in dist/.
    if (existsSync(join(DIST, targetPath)) && statSync(join(DIST, targetPath)).isFile()) return;
    problems.push(`${source}: no built page for ${targetPath}`);
    return;
  }
  if (anchor && !idsByPage.get(page).has(decodeURIComponent(anchor))) {
    problems.push(`${source}: #${anchor} not found on ${page}`);
  }
}

for (const [pagePath, html] of htmlByPage) {
  if (pagePath === '/404.html') continue;
  for (const match of html.matchAll(/<a\s[^>]*?href=["']([^"']*)["']/g)) {
    const href = match[1].replace(/&amp;/g, '&');
    if (href === '' || /^(https?:|mailto:|tel:|javascript:|\/\/)/.test(href)) continue;
    const [beforeHash, anchor = ''] = href.split('#');
    const beforeQuery = beforeHash.split('?')[0];
    let targetPath;
    if (beforeQuery === '') {
      targetPath = pagePath;
    } else if (beforeQuery.startsWith('/')) {
      if (!beforeQuery.startsWith(BASE + '/') && beforeQuery !== BASE) {
        problems.push(`${pagePath}: link outside base: ${href}`);
        continue;
      }
      targetPath = beforeQuery.slice(BASE.length) || '/';
    } else {
      targetPath = posix.resolve(pagePath, beforeQuery);
    }
    checkTarget(`${pagePath} -> ${href}`, targetPath, anchor);
  }
}

const config = readFileSync(DOCS_CONFIG, 'utf8');
const anchorsBlock = config.slice(config.indexOf("'anchors' =>"));
for (const match of anchorsBlock.matchAll(/'([\w.-]+)'\s*=>\s*'([^']*)'/g)) {
  const [, key, path] = match;
  const [beforeHash, anchor = ''] = path.split('#');
  checkTarget(`config/docs.php '${key}'`, '/' + beforeHash.replace(/^\//, ''), anchor);
}

if (problems.length > 0) {
  console.error(`Found ${problems.length} broken link(s):`);
  for (const problem of problems) console.error(`  ${problem}`);
  process.exit(1);
}
console.log(`Links OK (${htmlByPage.size} pages checked).`);
