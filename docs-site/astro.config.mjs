// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// Deployed to https://geocodio.github.io/yak/
// If a custom domain is added later, set `site` to the bare domain and
// remove `base`.
const site = 'https://geocodio.github.io';
const base = '/yak';

export default defineConfig({
  site,
  base,
  trailingSlash: 'always',
  integrations: [
    starlight({
      title: 'Yak',
      description: 'Yak is a coding agent for your team. It takes work from Slack, Linear, Sentry, GitHub, email and the dashboard, runs Claude in an isolated sandbox, opens pull requests for human review and serves previews.',
      logo: {
        src: './src/assets/mascot.png',
        alt: 'Yak mascot',
        replacesTitle: false,
      },
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/geocodio/yak' },
      ],
      favicon: '/favicon.png',
      head: [
        {
          tag: 'meta',
          attrs: { property: 'og:image', content: `${site}${base}/og-image.png` },
        },
        {
          tag: 'meta',
          attrs: { name: 'twitter:card', content: 'summary_large_image' },
        },
      ],
      lastUpdated: true,
      customCss: [
        './src/styles/yak-theme.css',
      ],
      components: {
        // Swap Starlight's default components for Yak-themed variants
        // when needed. Keeping overrides minimal for now — the design
        // tokens handle most of the visual identity.
      },
      sidebar: [
        {
          label: 'Start here',
          items: [
            { slug: 'concepts' },
          ],
        },
        {
          label: 'Work with Yak',
          items: [
            { slug: 'threads' },
            { slug: 'multi-repo' },
            { slug: 'sub-threads' },
            { slug: 'previews' },
            { slug: 'teleport' },
            { slug: 'ssh' },
            { slug: 'cli' },
            { slug: 'claude-accounts' },
            { slug: 'notifications' },
            { slug: 'pr-review' },
            { slug: 'video-walkthroughs' },
            { slug: 'dashboard' },
          ],
        },
        {
          label: 'Configure',
          items: [
            { slug: 'channels' },
            { slug: 'repositories' },
            { slug: 'repo-config' },
            { slug: 'prompting' },
          ],
        },
        {
          label: 'Run Yak',
          items: [
            { slug: 'setup' },
            { slug: 'upgrading' },
            { slug: 'operations' },
            { slug: 'architecture' },
            { slug: 'troubleshooting' },
          ],
        },
        {
          label: 'Contributing',
          items: [
            { slug: 'development' },
          ],
        },
      ],
    }),
  ],
});
