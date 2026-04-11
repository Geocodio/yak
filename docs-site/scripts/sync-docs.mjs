#!/usr/bin/env node
/**
 * Sync docs/*.md from the repo root into Starlight's content collection.
 *
 * Transforms each file by:
 *  - Extracting the first `# Heading` as the page title
 *  - Prepending YAML front matter (title, description, sidebar order)
 *  - Stripping the first `# Heading` so Starlight doesn't render it twice
 *
 * The source files in ../docs stay pristine so GitHub's markdown viewer
 * renders them cleanly. Starlight reads the transformed copies from
 * src/content/docs/.
 */

import { readdirSync, readFileSync, writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const SOURCE_DIR = resolve(__dirname, '../../docs');
const TARGET_DIR = resolve(__dirname, '../src/content/docs');

// Sidebar grouping and order. Files not listed here are synced but excluded
// from explicit ordering — they'll appear alphabetically if included.
const PAGES = [
  { file: 'setup.md',           title: 'Setup Guide',    description: 'Provision a Yak server with Ansible in one command.',                    group: 'getting-started', order: 1 },
  { file: 'channels.md',        title: 'Channels',       description: 'Configure Slack, Linear, Sentry, GitHub, Drone, and the manual CLI.',    group: 'getting-started', order: 2 },
  { file: 'repositories.md',    title: 'Repositories',   description: 'Add and manage repositories, setup tasks, and CLAUDE.md conventions.',   group: 'getting-started', order: 3 },
  { file: 'architecture.md',    title: 'Architecture',   description: 'How Yak works under the hood: two-tier AI, drivers, state machine.',    group: 'reference',       order: 1 },
  { file: 'prompting.md',       title: 'Prompting',      description: 'Three prompt layers, system prompt, task templates, MCP servers.',       group: 'reference',       order: 2 },
  { file: 'troubleshooting.md', title: 'Troubleshooting',description: 'Common problems and how to diagnose them.',                             group: 'operations',      order: 1 },
  { file: 'development.md',     title: 'Development',    description: 'Local setup, running tests, code style, and adding new channel drivers.',group: 'contributing',    order: 1 },
];

// Exclude the GitHub-facing folder index. Starlight has its own homepage.
const EXCLUDED = new Set(['README.md']);

function stripFirstHeading(content) {
  const lines = content.split('\n');
  const headingIndex = lines.findIndex((line) => /^#\s+\S/.test(line));
  if (headingIndex === -1) return content;

  // Remove the heading line and any blank line immediately after it.
  const toRemove = lines[headingIndex + 1]?.trim() === '' ? 2 : 1;
  lines.splice(headingIndex, toRemove);
  return lines.join('\n');
}

function buildFrontmatter(page) {
  return [
    '---',
    `title: ${JSON.stringify(page.title)}`,
    `description: ${JSON.stringify(page.description)}`,
    `sidebar:`,
    `  order: ${page.order}`,
    '---',
    '',
  ].join('\n');
}

function rewriteRelativeLinks(content) {
  // Convert [text](other.md) to [text](/other/) for Starlight's routing.
  // Only applies to bare .md filenames — leave anchors and full URLs alone.
  return content.replace(
    /\[([^\]]+)\]\(([a-z0-9-]+)\.md(#[a-z0-9-]+)?\)/gi,
    (_, text, slug, anchor) => `[${text}](/${slug}/${anchor ?? ''})`,
  );
}

function main() {
  if (!existsSync(SOURCE_DIR)) {
    console.error(`Source docs directory not found: ${SOURCE_DIR}`);
    process.exit(1);
  }

  // Nuke and recreate the target dir so stale files are cleaned up.
  if (existsSync(TARGET_DIR)) {
    rmSync(TARGET_DIR, { recursive: true, force: true });
  }
  mkdirSync(TARGET_DIR, { recursive: true });

  const sourceFiles = readdirSync(SOURCE_DIR).filter(
    (name) => name.endsWith('.md') && !EXCLUDED.has(name),
  );

  let synced = 0;
  for (const filename of sourceFiles) {
    const page = PAGES.find((p) => p.file === filename);
    if (!page) {
      console.warn(`  skip: ${filename} (not in PAGES list)`);
      continue;
    }

    const sourcePath = join(SOURCE_DIR, filename);
    const raw = readFileSync(sourcePath, 'utf8');

    const stripped = stripFirstHeading(raw);
    const rewritten = rewriteRelativeLinks(stripped);
    const output = buildFrontmatter(page) + rewritten;

    const targetPath = join(TARGET_DIR, filename);
    writeFileSync(targetPath, output, 'utf8');
    synced += 1;
    console.log(`  ✓ ${filename}`);
  }

  // Copy static Starlight-only pages (homepage, 404). These are maintained
  // in docs-static/ because they aren't part of the /docs folder — they
  // only exist on the hosted site.
  const staticDir = resolve(__dirname, '../src/content/docs-static');
  if (existsSync(staticDir)) {
    for (const file of readdirSync(staticDir)) {
      const content = readFileSync(join(staticDir, file), 'utf8');
      writeFileSync(join(TARGET_DIR, file), content, 'utf8');
      console.log(`  ✓ ${file}`);
      synced += 1;
    }
  }

  console.log(`\nSynced ${synced} pages to ${TARGET_DIR}`);
}

main();
