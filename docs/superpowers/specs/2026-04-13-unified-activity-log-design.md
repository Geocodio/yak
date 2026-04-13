# Unified Activity Log

**Date:** 2026-04-13
**Status:** Approved

## Problem

The task detail page has two sections — Timeline (Section 4) and Session Log (Section 8) — that show the same `task_logs` data. The timeline is a simplified dot-and-line view; the session log is an expandable CI-style view. For most tasks, these look nearly identical and the redundancy is confusing.

## Solution

Replace both sections with a single **"Activity"** card. The existing session log format is the base. Lifecycle/milestone events are visually distinguished with a colored left border and bold text so they stand out from tool_use/assistant noise. A follow button auto-scrolls the log during active tasks.

## Changes

### Remove

- **Section 4 (Timeline)** — lines 108-127 of `task-detail.blade.php`. Delete entirely.

### Modify

- **Section 8 (Session Log)** — rename to "Activity". Add milestone highlighting and follow behavior.

## Milestone Detection

A log entry is a **milestone** if either condition is true:

1. `metadata.type` is NOT `tool_use` AND NOT `assistant`
2. `level` is `error` or `warning` (regardless of metadata type)

This surfaces lifecycle events (task created, assessment complete, PR created, task completed) and any errors/warnings.

## Milestone Visual Treatment

Milestone log entries get:

- **Left border:** `3px solid` using the log level color:
  - info: `#5a8da5`
  - warning: `#d4915e`
  - error: `#b85450`
- **Bold message text:** `font-weight: 600` on the message span
- **Subtle background:** `rgba` tint matching the level color on the header row
- **No expand chevron:** milestone entries typically have no expandable output, so hide the chevron (use `visibility: hidden` to preserve alignment)

Non-milestone entries remain exactly as they are today.

## Follow / Auto-Scroll

### UI

- A "Following" / "Follow" toggle button in the header row, next to the stats text
- Only visible when `$this->isActiveStatus()` returns true
- When active: shows a pulsing dot and "Following" label
- When inactive: shows a static dot and "Follow" label

### Behavior

- The log list gets a scrollable container with `max-height` and `overflow-y: auto`
- Managed via Alpine.js (`x-data`, `x-ref`, `x-effect`)
- **Default state:** Following is ON when the task has an active status
- **On poll refresh:** If following is active, scroll the container to the bottom
- **Manual scroll up:** If the user scrolls up (scroll position is not at the bottom), disable following automatically
- **Re-enable:** Clicking the "Follow" button scrolls to bottom and re-enables auto-follow
- **Completed tasks:** Button hidden, no auto-scroll, container scrolls naturally

### Implementation

Use Alpine.js on the log container:

```
x-data="{ following: @js($this->isActiveStatus()) }"
x-ref="logContainer"
x-effect="if (following) { $nextTick(() => $refs.logContainer.scrollTo({ top: $refs.logContainer.scrollHeight, behavior: 'smooth' }) }) }"
@scroll="following = ($refs.logContainer.scrollTop + $refs.logContainer.clientHeight >= $refs.logContainer.scrollHeight - 20) ? following : false"
```

The follow button:

```
@click="following = true; $refs.logContainer.scrollTo({ top: $refs.logContainer.scrollHeight, behavior: 'smooth' })"
```

## Section Header

Replace current header:

```
Session Log
24 entries · 18 turns · 3m 12s · $0.47
```

With:

```
Activity
[Following] button (if active)    24 entries · 18 turns · 3m 12s · $0.47
```

## Files to Modify

1. **`resources/views/livewire/tasks/task-detail.blade.php`**
   - Delete Section 4 (Timeline), lines 108-127
   - Modify Section 8: rename heading, add milestone classes, add follow button, add Alpine.js scroll behavior, add scrollable container

2. **`app/Livewire/Tasks/TaskDetail.php`**
   - Remove any timeline-only computed properties (if any exist)
   - No new Livewire properties needed — follow state is Alpine-only (client-side)

3. **`tests/Feature/Livewire/Tasks/TaskDetailTest.php`**
   - Update timeline-related test assertions to reference "Activity" section
   - Add test: milestone logs render with milestone CSS class
   - Add test: non-milestone logs (tool_use, assistant) do NOT have milestone class
   - Add test: follow button visible only for active tasks
   - Remove any tests that assert the old Timeline section exists

## Out of Scope

- Filtering or search within the activity log
- Collapsing/hiding non-milestone entries
- Persisting follow state across page loads
