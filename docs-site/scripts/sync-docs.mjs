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

import { readdirSync, readFileSync, writeFileSync, copyFileSync, cpSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { join, dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const SOURCE_DIR = resolve(__dirname, '../../docs');
const TARGET_DIR = resolve(__dirname, '../src/content/docs');
const SCHEMAS_DIR = resolve(__dirname, '../../schemas');
const SCHEMAS_TARGET_DIR = resolve(__dirname, '../public/schemas');

// Must match `base` in astro.config.mjs. Internal `<a href>` in Markdown
// is NOT auto-prefixed with Astro's base, so links must be written with
// the prefix baked in. Kept here as a single source of truth for the sync.
const SITE_BASE = '/yak';

// Every published page. A file that is not listed here is skipped. The sidebar
// in astro.config.mjs lists the same slugs and decides grouping and order.
const PAGES = [
  { file: "concepts.md", title: "Concepts", description: "Threads, turns, tasks, sandboxes and Claude accounts, and how they relate.", group: "start", order: 1 },
  { file: "threads.md", title: "Threads", description: "Start a thread, steer Claude, read the thread page, and share or keep a thread private.", group: "work", order: 1 },
  { file: "multi-repo.md", title: "Multi-repo threads", description: "Work across several repositories in one thread, with one pull request per changed repository.", group: "work", order: 2 },
  { file: "sub-threads.md", title: "Sub-threads", description: "Split work into sub-threads that run side by side and report back to the parent.", group: "work", order: 3 },
  { file: "previews.md", title: "Previews", description: "Thread previews of live work, branch deployments for pull requests, and preview repair.", group: "work", order: 4 },
  { file: "teleport.md", title: "Teleport", description: "Move a Claude Code session between your computer and a Yak thread.", group: "work", order: 5 },
  { file: "ssh.md", title: "SSH and terminal", description: "Open a shell in a thread sandbox from the browser or with yak ssh.", group: "work", order: 6 },
  { file: "cli.md", title: "yak CLI", description: "Command and flag reference for the yak command and the Claude Code plugin.", group: "work", order: 7 },
  { file: "claude-accounts.md", title: "Claude accounts", description: "Add Claude accounts, choose which one a thread uses, and handle usage limits.", group: "work", order: 8 },
  { file: "notifications.md", title: "Notifications", description: "Push notifications, Slack direct messages and email.", group: "work", order: 9 },
  { file: "pr-review.md", title: "PR Review", description: "Let Yak review pull requests with line-level comments and a feedback dashboard.", group: "work", order: 10 },
  { file: "video-walkthroughs.md", title: "Video walkthroughs", description: "Recorded walkthroughs of UI changes and the installation-wide video theme.", group: "work", order: 11 },
  { file: "dashboard.md", title: "Dashboard pages", description: "What each dashboard page shows.", group: "work", order: 12 },
  { file: "channels.md", title: "Channels", description: "Configure Slack, Linear, Sentry, email, GitHub and Drone.", group: "configure", order: 1 },
  { file: "repositories.md", title: "Repositories", description: "Add and manage repositories, setup, routing and per-repository settings.", group: "configure", order: 2 },
  { file: "repo-config.md", title: "Repository config", description: "Commit .yak/ files to a repository to control how Yak behaves there.", group: "configure", order: 3 },
  { file: "prompting.md", title: "Prompting", description: "How Yak builds what Claude sees: prompt layers, templates, tools and MCP servers.", group: "configure", order: 4 },
  { file: "setup.md", title: "Setup", description: "Provision a Yak server with Ansible.", group: "run", order: 1 },
  { file: "upgrading.md", title: "Upgrading to threads", description: "Move an existing installation to the thread model.", group: "run", order: 2 },
  { file: "operations.md", title: "Operations", description: "Host components, scheduled commands, capacity settings and datasets.", group: "run", order: 3 },
  { file: "architecture.md", title: "Architecture", description: "How Yak works inside: the thread model, the life of a turn, sandboxes and safety.", group: "run", order: 4 },
  { file: "troubleshooting.md", title: "Troubleshooting", description: "Common problems and how to diagnose them.", group: "run", order: 5 },
  { file: "development.md", title: "Development", description: "Local setup, tests, code style, adding a channel and working on the docs.", group: "contributing", order: 1 },
];

// Exclude the GitHub-facing folder index. Starlight has its own homepage.
const EXCLUDED = new Set(['README.md']);

// Image/asset extensions copied alongside the markdown so relative
// references like ![](foo.png) resolve under Astro's asset pipeline.
const ASSET_EXTENSIONS = new Set(['.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.avif']);

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
  // Convert [text](other.md) to [text](/yak/other/) for Starlight's routing.
  // Only applies to bare .md filenames — leave anchors and full URLs alone.
  return content.replace(
    /\[([^\]]+)\]\(([a-z0-9-]+)\.md(#[a-z0-9-]+)?\)/gi,
    (_, text, slug, anchor) => `[${text}](${SITE_BASE}/${slug}/${anchor ?? ''})`,
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

  const entries = readdirSync(SOURCE_DIR);
  const sourceFiles = entries.filter(
    (name) => name.endsWith('.md') && !EXCLUDED.has(name),
  );
  const assetFiles = entries.filter((name) => ASSET_EXTENSIONS.has(extname(name).toLowerCase()));

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

  for (const filename of assetFiles) {
    copyFileSync(join(SOURCE_DIR, filename), join(TARGET_DIR, filename));
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

  // Publish the JSON Schemas for .yak/ files next to the docs.
  if (existsSync(SCHEMAS_DIR)) {
    cpSync(SCHEMAS_DIR, SCHEMAS_TARGET_DIR, { recursive: true });
    console.log('  ✓ schemas/');
  }

  console.log(`\nSynced ${synced} pages to ${TARGET_DIR}`);
}

main();
