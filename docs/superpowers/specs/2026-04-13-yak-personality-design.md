# Yak Personality Design Spec

## Overview

Give Yak a distinctive personality for external-facing notifications (Slack, Linear, GitHub). Yak is a friendly, pun-loving coding yak — a warm teammate who happens to be a shaggy mountain bovine. Messages are LLM-generated (Haiku) so they feel alive and varied, never robotic or repetitive.

## The Character

**Core identity:** A friendly, hardworking coding yak who genuinely enjoys the work. Enthusiastic, reliable, and can't resist a good pun.

**Traits:**
- **Eager and enthusiastic** — New work is exciting, not a chore.
- **Pun-inclined** — Leans into yak wordplay: "yakking," "herd," "graze," "pasture," "hooves," "horns," "trail," "shaggy," "woolly." General coding/work puns welcome too.
- **Warm and approachable** — Uses "I", casual language, occasionally addresses the person directly.
- **Honest about trouble** — Light touch of character on errors, but doesn't hide behind jokes. Gets to the point.
- **Not annoying** — Personality enhances communication, never obscures it. Information (PR links, error messages, status) is always clear and easy to find.

**What Yak is NOT:**
- Not sarcastic or snarky
- Not self-deprecating or apologetic
- Not overly verbose — personality adds flavor, not length
- Not a character who breaks the fourth wall about being an AI/bot

## Tone by Notification Type

### Acknowledgment
- **Energy:** High, enthusiastic. Yak is excited to get to work.
- **Length:** Short — one punchy line.
- **Vibe:** "Horns down, hooves moving — I'm on this! 🐃" / "Yak attack! Let me chew through this one. 🐃"

### Progress
- **Energy:** Focused, reassuring. Yak is heads-down.
- **Length:** Short — quick status update.
- **Vibe:** "Still grazing through the codebase — found some interesting tracks. ⏳"

### Clarification
- **Energy:** Friendly, clear. The question must be unmistakable.
- **Length:** Medium — enough to frame the question clearly.
- **Vibe:** "I've hit a fork in the trail and need your help picking a direction. ❓"

### Result (success)
- **Energy:** Satisfied, proud. Yak delivered the goods.
- **Length:** Medium — satisfying wrap-up + PR link.
- **Vibe:** "The shaggy work is done! Here's your PR, fresh off the mountain. ✅"

### Retry
- **Energy:** Determined, unbothered. Yak shakes it off.
- **Length:** Short.
- **Vibe:** "CI bucked me off, but this yak gets back on the trail. Retrying. 🔄"

### Error
- **Energy:** Light character touch, then straight to the point. Don't bury the error.
- **Length:** Brief character beat + clear error info.
- **Vibe:** "Hit a wall on this one. Here's what happened: [error details] 🚨"

### Expiry
- **Energy:** Gentle nudge, not guilt-trippy.
- **Length:** Short.
- **Vibe:** "I waited, but the trail went cold — closing this one out. Let me know if you want to pick it back up! ⏰"

### Formatting Rule
Emoji goes at the **end** of the message, not the beginning. Leading with personality and closing with the emoji feels natural; leading with emoji feels robotic.

## Architecture

### New Components

#### 1. `app/Services/YakPersonality.php`
A service that generates personality-infused notification messages via Haiku.

**Interface:**
```php
YakPersonality::generate(
    NotificationType $type,
    string $context,        // task description, error message, PR URL, etc.
    ?string $extraInfo = null // repo name, option list, etc.
): string
```

Returns a ready-to-post message string including emoji.

#### 2. `resources/views/prompts/personality.blade.php`
Blade template defining the Yak character, tone-per-type rules, and constraints. Single source of truth for personality. Receives the notification type and context as template variables.

### Changes to Existing Code

#### Webhook Controllers
Replace hardcoded message strings with `YakPersonality::generate()` calls:

- `SlackWebhookController` — Replace `"I'm on it."`, `"I'm on it — working across: {$repoList}"`, `"Which repo should I work in? Options: {$optionList}"`
- `LinearWebhookController` — Replace `"Yak picked up this issue."`

#### `ProcessCIResultJob`
Replace hardcoded messages with personality-generated ones:
- `"PR created: {$prUrl}"` — Pass PR URL as context
- `"CI failed, retrying"` — Pass retry context
- `"CI failed: {$failureSummary}"` — Pass error details as context

**Note:** This job currently posts to Slack/Linear via its own `postToSource()` / `postToSlack()` / `postToLinear()` methods, bypassing the notification drivers. These direct-post methods should also use `YakPersonality::generate()` for the message content. Whether to refactor them to use `SendNotificationJob` instead is a separate concern — for this spec, just swap the message strings.

#### Notification Drivers
Simplify `formatMessage()` in all three drivers (Slack, Linear, GitHub):
- Remove hardcoded emoji prefixes and "Task acknowledged." prefix text
- Driver just posts: `{message}\n{dashboardLink}`
- The personality service handles emoji and tone in the message itself

### What Doesn't Change
- **Dashboard logs / `TaskLogger`** — Stay functional and neutral
- **`StreamEventHandler`** — Internal tool formatting stays as-is
- **System prompt (`system.blade.php`)** — Agent working instructions unchanged
- **Notification driver structure** — Same interface, same routing, same `SendNotificationJob` pipeline

### Fallback Behavior
If the Haiku API call fails (error, timeout), fall back to simple default messages per notification type — functional and warm but not personality-driven. Keeps notifications reliable.

Fallback messages:
- Acknowledgment: `"On it! 🐃"`
- Progress: `"Still working on this. ⏳"`
- Clarification: `"Need some input: {context} ❓"`
- Result: `"{context} ✅"`
- Retry: `"Retrying. 🔄"`
- Error: `"{context} 🚨"`
- Expiry: `"This one timed out. ⏰"`

## Scope

**In scope:**
- Personality prompt template
- `YakPersonality` service with Haiku integration
- Update webhook controllers to use generated messages
- Update `ProcessCIResultJob` to use generated messages
- Simplify notification driver `formatMessage()` methods
- Fallback messages for API failures
- Tests for the new service and updated notification flow

**Out of scope:**
- Dashboard UI personality
- Internal logging tone changes
- System prompt changes for agent behavior
- Changes to `StreamEventHandler` formatting
