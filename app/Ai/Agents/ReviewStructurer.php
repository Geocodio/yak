<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Converts a free-form PR review (prose with optional ```suggestion fences)
 * into the shape we persist in `pr_reviews` / `pr_review_comments`.
 *
 * The sandboxed Claude Code agent reads the code, runs tests, and writes a
 * rich prose review — it doesn't need to emit strict JSON. This agent
 * takes that prose output and translates it using the AI SDK's structured
 * output so we never parse JSON from arbitrary text.
 */
#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class ReviewStructurer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a transcription service. You'll receive a free-form pull-request
review produced by another agent and convert it into the structured shape
below. Do NOT introduce new findings, soften existing ones, or editorialize
— extract only what's in the source review, verbatim where possible.

Rules:
- Copy each finding's file path, line number, severity, category, and body
  from the source review. Preserve markdown formatting inside the body,
  including any ```suggestion fenced blocks.
- Set `suggestion_loc` to the number of changed lines in the
  suggestion block (only when a ```suggestion fence is present in the body).
- Only set `start_line` when the source review's prose explicitly names a
  `LINE-LINE` range (e.g. `path/to/file.php:138-140`, "lines 138 through
  140"). Do NOT infer a range by looking at the suggestion fence content
  or the surrounding diff. If the prose names a single line, leave
  `start_line` null even when the fence has multiple lines.
- The range `(line - start_line + 1)` must approximately match
  `suggestion_loc` — the fence REPLACES every line in the range. If the
  prose's range is much wider than the fence (e.g. covering an entire
  function when the fence is just a docblock), DROP the range: leave
  `start_line` null and only emit `line`. Posting the wider range would
  delete the unchanged lines in between when the suggestion is accepted.
- Never set `start_line` equal to or greater than `line`.
- Map verdict wording to one of: "Approve", "Approve with suggestions",
  "Request changes". If the reviewer uses different wording, pick the
  closest match.
- Severity must be one of: "must_fix", "should_fix", "consider".
- Drop sections with no findings (e.g. omit a "Must Fix" header if the
  reviewer listed none).
- If the reviewer offered no verdict, infer it from the findings:
  any must_fix = "Request changes"; else any should_fix = "Approve with
  suggestions"; else "Approve".
- For incremental reviews you'll receive a "Prior findings" section. Emit one
  `prior_findings` entry per prior finding the source review addresses. Map
  the source review's status keyword (FIXED / STILL_OUTSTANDING / UNTOUCHED /
  WITHDRAWN) to lower-case for `status`. Copy the reply body verbatim. For
  UNTOUCHED entries omit `reply_body`.
- For non-incremental reviews emit `prior_findings: []`.
PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $finding = $schema->object([
            'file' => $schema->string()->required()
                ->description('Repo-relative path to the file, e.g. `app/Services/Foo.php`.'),
            'line' => $schema->integer()->required()
                ->description('Line the comment anchors to. For range suggestions, this is the LAST line in the range.'),
            'start_line' => $schema->integer()
                ->description('First line of the original range a multi-line suggestion replaces. Must be strictly less than `line`. Omit for single-line suggestions or findings without a suggestion fence.'),
            'severity' => $schema->string()->enum(['must_fix', 'should_fix', 'consider'])->required(),
            'category' => $schema->string()->required()
                ->description('Rubric category, e.g. `Simplicity`, `Test Quality`, `Performance`.'),
            'body' => $schema->string()->required()
                ->description('Full markdown body of the comment. May include a ```suggestion fence.'),
            'suggestion_loc' => $schema->integer()
                ->description('Number of lines inside the suggestion block. Omit when the body has no suggestion fence.'),
        ]);

        $priorFinding = $schema->object([
            'id' => $schema->integer()->required()
                ->description('GitHub comment id of the prior finding (matches `pr_review_comments.github_comment_id`).'),
            'status' => $schema->string()
                ->enum(['fixed', 'still_outstanding', 'untouched', 'withdrawn'])
                ->required()
                ->description('Resolution decision for this prior finding.'),
            'reply_body' => $schema->string()
                ->description('Markdown body to post as the thread reply. Required for fixed/still_outstanding/withdrawn; ignored for untouched.'),
        ]);

        return [
            'summary' => $schema->string()->required()
                ->description('2–3 sentence walkthrough of what the PR does.'),
            'verdict' => $schema->string()
                ->enum(['Approve', 'Approve with suggestions', 'Request changes'])
                ->required(),
            'verdict_detail' => $schema->string()->required()
                ->description('One sentence justifying the verdict.'),
            'findings' => $schema->array()->items($finding)->required(),
            'prior_findings' => $schema->array()->items($priorFinding)
                ->description('Resolution decisions for prior unresolved findings, when this is an incremental review.'),
        ];
    }
}
