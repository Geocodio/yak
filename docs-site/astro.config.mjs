// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';
import { GROUPS, PAGES } from './scripts/pages.mjs';

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
    // Renders ```mermaid blocks in the browser; must come before Starlight.
    mermaid({ theme: 'default', autoTheme: true }),
    starlight({
      title: 'Yak',
      description: 'Yak is a coding agent that drafts PRs for small fixes, reviews PRs line by line, and serves a preview for every branch. A human reviews and merges everything.',
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
        // when needed. Keeping overrides minimal for now, the design
        // tokens handle most of the visual identity.
      },
      sidebar: GROUPS.map((group) => ({
        label: group.label,
        items: PAGES.filter((page) => page.group === group.key)
          .sort((left, right) => left.order - right.order)
          .map((page) => ({ slug: page.file.replace(/\.md$/, '') })),
      })),
    }),
  ],
});
