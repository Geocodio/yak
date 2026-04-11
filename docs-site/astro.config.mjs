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
      description: 'Autonomous coding agent for papercuts.',
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
      editLink: {
        baseUrl: 'https://github.com/geocodio/yak/edit/main/docs/',
      },
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
          label: 'Getting Started',
          items: [
            { slug: 'setup' },
            { slug: 'channels' },
            { slug: 'repositories' },
          ],
        },
        {
          label: 'Reference',
          items: [
            { slug: 'architecture' },
            { slug: 'prompting' },
          ],
        },
        {
          label: 'Operations',
          items: [
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
