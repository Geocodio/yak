// The single list behind the sidebar and the synced front matter. Every
// docs/*.md page (except EXCLUDED in sync-docs.mjs) must be listed here; the
// sync fails on an unlisted page. astro.config.mjs builds the sidebar from it.
export const PAGES = [
  { file: 'overview.md',        title: 'How Yak works',  description: 'The channel map, what a task is, what Yak does and does not do, and a dashboard tour.', group: 'getting-started', order: 0 },
  { file: 'setup.md',           title: 'Setup Guide',    description: 'Provision a Yak server with Ansible in one command.',                    group: 'getting-started', order: 1 },
  { file: 'channels.md',        title: 'Channels',       description: 'Configure Slack, Linear, Sentry, GitHub, Drone, and the manual CLI.',    group: 'getting-started', order: 2 },
  { file: 'repositories.md',    title: 'Repositories',   description: 'Add and manage repositories, setup tasks, and CLAUDE.md conventions.',   group: 'getting-started', order: 3 },
  { file: 'branch-deployments.md', title: 'Branch Deployments', description: 'A live preview URL for every open PR on an opted-in repo.',        group: 'workflows',       order: 3 },
  { file: 'video-walkthroughs.md', title: 'Video Walkthroughs', description: 'Recorded walkthroughs on PRs and the installation-wide video theme.', group: 'workflows',       order: 4 },
  { file: 'pr-review.md',       title: 'PR Review',      description: 'Enable Yak to review pull requests with line-level comments and a feedback dashboard.', group: 'workflows',       order: 1 },
  { file: 'risk-based-approval.md', title: 'Risk-Based Approval', description: 'Let Yak approve low-risk PRs, with shadow mode and risk profiles.', group: 'workflows',       order: 2 },
  { file: 'architecture.md',    title: 'Architecture',   description: 'How Yak works under the hood: two-tier AI, drivers, state machine.',    group: 'reference',       order: 1 },
  { file: 'prompting.md',       title: 'Prompting',      description: 'Three prompt layers, system prompt, task templates, MCP servers.',       group: 'reference',       order: 2 },
  { file: 'troubleshooting.md', title: 'Troubleshooting',description: 'Common problems and how to diagnose them.',                             group: 'operations',      order: 1 },
  { file: 'development.md',     title: 'Development',    description: 'Local setup, running tests, code style, and adding new channel drivers.',group: 'contributing',    order: 1 },
];

// Sidebar groups in display order; each group's pages sort by `order`.
export const GROUPS = [
  { key: 'getting-started', label: 'Getting Started' },
  { key: 'workflows', label: 'Workflows' },
  { key: 'reference', label: 'Reference' },
  { key: 'operations', label: 'Operations' },
  { key: 'contributing', label: 'Contributing' },
];
