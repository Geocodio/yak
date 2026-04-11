# Yak Docs Site

Astro + [Starlight](https://starlight.astro.build/) site that renders the markdown files in `../docs/` with a custom theme matching Yak's design system.

Published to: https://geocodio.github.io/yak/

## How it works

The source of truth for documentation content is the `docs/` folder at the repo root — plain markdown, readable on GitHub directly. This site wraps those files with navigation, search, and custom theming.

On every dev start or build, `scripts/sync-docs.mjs`:

1. Reads `../docs/*.md` (skipping `README.md`)
2. Extracts the first `# Heading` as the page title
3. Prepends YAML front matter with title, description, and sidebar order
4. Rewrites relative `.md` links to Starlight slug routes
5. Writes the transformed files to `src/content/docs/` (git-ignored)
6. Copies Starlight-only pages (homepage, etc.) from `src/content/docs-static/`

Sidebar order and descriptions are configured in the `PAGES` array inside `scripts/sync-docs.mjs`.

## Working on the site

### Editing documentation content

Edit the markdown files in `../docs/`. Run `npm run dev` here to see changes live.

**Never** edit files in `src/content/docs/` — they're regenerated on every sync and any changes will be wiped.

### Editing theme or structure

- **Visual theme** — `src/styles/yak-theme.css` (Yak design tokens, typography, components)
- **Sidebar and top nav** — `astro.config.mjs`
- **Homepage** — `src/content/docs-static/index.mdx` (uses Starlight's splash template)
- **Favicon and OG image** — `public/favicon.png`, `public/og-image.png`
- **Logo** — `src/assets/mascot.png`
- **Sidebar order** — the `PAGES` array in `scripts/sync-docs.mjs`

## Commands

```bash
# One-time install
npm install

# Start the dev server (runs sync automatically, hot reloads on edits)
npm run dev
# → http://localhost:4321/yak/

# Build for production
npm run build
# → dist/

# Preview the production build locally
npm run preview

# Re-run the content sync only (rarely needed — dev and build do it automatically)
npm run sync
```

## Deployment

The `.github/workflows/docs.yml` workflow builds and deploys this site to GitHub Pages on every push to `main` that touches `docs/`, `docs-site/`, or the workflow itself.

To enable the first time:

1. Go to the repo's **Settings → Pages**
2. Set **Source** to **GitHub Actions**
3. Push a commit that changes `docs/` or `docs-site/` — the workflow runs automatically

The default deployed URL is `https://geocodio.github.io/yak/`. To move to a custom domain:

1. Add a `CNAME` file under `docs-site/public/` containing your domain
2. In `astro.config.mjs`, change `site` to `https://your-domain` and remove the `base: '/yak'` line
3. Configure DNS: add a CNAME record pointing to `geocodio.github.io`
4. In **Settings → Pages**, set the custom domain
