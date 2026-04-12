# Prompt Editor — Design Spec

A web-based prompt editor for Yak's admin dashboard that lets you view, edit, and iterate on the prompts that drive Yak's task execution. Built for a single power user (the Yak admin), not a multi-tenant editing experience.

## Context

Today, prompts are Blade templates in `resources/views/prompts/` rendered by `YakPromptBuilder`. The builder routes to the right template based on task source (Sentry, Linear, Slack, flaky-test) and mode (Setup, Fix, Research), then renders with task metadata. Editing prompts means changing files in an IDE and redeploying.

This feature adds an in-app editor that stores customized prompts in the database with version history, while preserving the Blade files as canonical defaults.

## Overall Layout

The prompt editor is a new page at `/prompts`, added to the existing sidebar nav alongside Tasks, Costs, Repositories, and Health.

**Layout zones:**

1. **Left sidebar (prompt list)** — two tiers:
   - **High-touch prompts** (prominent, top section): Sentry Fix, Linear Fix, Slack Fix, Flaky Test, System Rules — these are the prompts worth iterating on
   - **Advanced** (muted, collapsed section): Setup, Research, Retry, Clarification Reply, Channel: Linear, Channel: Sentry — set-and-forget prompts

2. **Editor pane (left split)** — CodeMirror 6 editor with Blade variable highlighting. Available variables for the current prompt type listed above the editor.

3. **Preview pane (right split)** — live-rendered prompt with sample task data. "Change sample" dropdown swaps between 2-3 fixtures per prompt type.

4. **Toolbar** — prompt name, type badge, History button (opens version list modal), Diff button (switches to merge view), Reset to Default, Save.

5. **Validation bar (bottom)** — warnings for unused available variables, errors for invalid variable references.

**Design:** Must follow `spec/design.md` tokens and patterns exactly. Yak color palette (cream, slate, orange, blue), Outfit font, Flux UI components for buttons/modals/dropdowns, `rounded-card` (28px) for panels, `#2b3640` dark background for the CodeMirror editor (matching existing code block treatment in task detail), glass effect for hero cards, solid surfaces for data-dense areas.

## Data Model

### `prompts` table

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | PK |
| `slug` | string, unique | Identifier matching the Blade template name (e.g. `sentry-fix`, `system`) |
| `label` | string | Display name ("Sentry Fix") |
| `category` | enum | `high_touch` or `advanced` — controls sidebar placement |
| `type` | enum | `task`, `system`, `channel`, `utility` — for the type badge |
| `content` | text | Current prompt content |
| `available_variables` | json | Array of variable names valid for this prompt |
| `is_customized` | boolean | `false` = using Blade file default, `true` = user has edited |
| `timestamps` | | |

### `prompt_versions` table

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | PK |
| `prompt_id` | FK | belongs to prompt |
| `content` | text | Snapshot of content at this version |
| `version` | integer | Auto-incrementing per prompt |
| `created_at` | timestamp | |

### Seeding

Initial prompt data is populated by the migration (not a seeder). The migration's `up()` method creates the tables and reads each Blade template from `resources/views/prompts/` to insert the initial rows with `is_customized = false`.

Future Yak updates that add new prompt types ship as new migrations that insert additional rows, checking `is_customized` before touching anything.

## YakPromptBuilder Changes

`YakPromptBuilder::renderView()` gains a database check:

1. Load the `Prompt` model by slug
2. If `is_customized` is true: render DB content via `Blade::render($prompt->content, $data)`
3. If `is_customized` is false (or row doesn't exist): fall back to existing `View::make()` — zero behavior change

The Blade files remain in the repo as canonical defaults. "Reset to Default" sets `is_customized = false`, restoring the Blade file as the active prompt.

**Upstream update behavior:** If a default template changes in a Yak update and the admin hasn't customized that prompt (`is_customized = false`), they get the new version automatically since it reads from the file. Customized prompts are never overwritten.

## CodeMirror 6 Integration

CodeMirror 6 installed via npm, initialized in an Alpine.js component (`x-data="promptEditor"`). The Alpine component wraps CodeMirror and syncs content to a Livewire property via `$wire.content` on change (debounced ~500ms for live preview). Save is explicit (button click or Cmd+S).

### Custom extensions

1. **Blade variable highlighting** — lightweight grammar that highlights `{{ $variable }}` tokens distinctly (yak-blue-light on tinted background). Prose stays default. Markdown-like headings (`## Section`) get bolder treatment.

2. **Variable autocomplete** — typing `{{` triggers a completion dropdown populated from the prompt's `available_variables`. Completion source passed in from Livewire when the prompt loads.

3. **Validation (linting)** — CodeMirror linter extension that:
   - Flags `{{ $var }}` where `$var` isn't in `available_variables` (error, inline squiggly)
   - Notes available variables not used in content (warning, bottom bar)

4. **Theme** — `#2b3640` background, `#d4d4d4` base text, variable tokens in yak-blue-light, cursor/selection from yak-orange.

### Live preview sample data

Sample fixture data for the preview pane lives in a config array on the `PromptEditor` component — one set of realistic values per prompt type (e.g. a real-looking Sentry error with stacktrace for `sentry-fix`, a Linear issue title and description for `linear-fix`). Two to three fixture variants per prompt type, selectable via the "Change sample" dropdown. No database storage for fixtures — they're hardcoded in the component since they rarely change.

### Diff view

Clicking "Diff" switches to a side-by-side CodeMirror merge view (`@codemirror/merge`) comparing current content against a selected previous version. Version picker dropdown selects which revision to diff against.

## Livewire Components & Routing

### Route

`GET /prompts` — new sidebar nav item.

### PromptEditor (single full-page Livewire component)

Owns the entire page: prompt list, editor, preview, version history, and diff. No sub-components — this is a single-page tool for one admin user.

**Properties:** `$selectedPromptId`, `$content`, `$previewHtml`, `$selectedVersionId`

**Actions:**
- `selectPrompt($id)` — loads content, available variables, resets preview
- `save()` — validates, creates `prompt_version`, updates `prompts.content`, sets `is_customized = true`
- `resetToDefault()` — confirmation modal (Flux), sets `is_customized = false`, reloads from Blade file
- `renderPreview()` — renders current `$content` via Blade string compiler with sample fixture data
- `loadVersionHistory()` — returns versions for selected prompt (Flux modal)
- `diffAgainstVersion($versionId)` — loads version content for merge view

### System rules special case

The system prompt (~12 rules) is presented as a sortable list using `wire:sort` for drag-and-drop reordering. Each rule is an editable block. Other prompt types are single-block editors.

## Testing

### Feature tests (Pest)

1. Selecting a prompt loads correct content
2. Saving creates a version, version counter increments
3. Saving multiple times creates ordered versions, loading a version returns correct content
4. Reset to default sets `is_customized = false`, subsequent render uses Blade file
5. Preview rendering interpolates variables into sample data
6. Saving content with invalid variable reference returns validation warning
7. `YakPromptBuilder` fallback: `is_customized = false` renders from Blade, `true` renders from DB

### Browser tests

8. Visiting `/prompts` and selecting a prompt initializes CodeMirror with content
9. Typing `{{` shows variable autocomplete dropdown
10. Dragging system rules reorders content

### Not tested

- CodeMirror internal syntax highlighting (CodeMirror's responsibility)
- Pixel-perfect styling (follows design tokens, verified visually)
