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
- Extract the explicit Risk signals into signals. Each dimension has value
  (integer 0..4), explanation, references (file/symbol/command citations).
  Copy model_confidence (integer 0..100), uncertainties and human_review_reasons.
  Do not infer missing measurements. Use -1 for missing numeric values and
  empty references for absent evidence. Never turn silence into certainty.
- Copy the explicit Risk assessment (low/high/unknown). Missing or ambiguous
  assessments are unknown. Never infer low risk from an approval or no findings.
- Copy each finding's file path, line number, severity, category, and body
  from the source review. When the source names a `LINE-LINE` range, use
  the LAST line. The body is the comment text after the
  `**[Category]** path:LINE —` prefix; do not repeat the category or the
  path inside it. Preserve markdown formatting inside the body. Findings
  are written as short inline comments (often one sentence); keep them
  that short.
- Copy ```original and ```suggestion fenced blocks into the body
  byte for byte: same lines, same indentation, same blank lines. Never
  reindent, reflow, complete, or fix their contents. A suggestion that
  differs from the source by a single character is discarded.
- `summary` is the source review's `## For the reviewer` section, copied
  verbatim as markdown (without the heading). If the source has no such
  section, write one or two sentences describing what the PR does.
- If the `## Findings` section is exactly `LGTM`, emit `findings: []`.
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
                ->description('Line the comment anchors to. For a `LINE-LINE` range, the LAST line.'),
            'severity' => $schema->string()->enum(['must_fix', 'should_fix', 'consider'])->required(),
            'category' => $schema->string()->required()
                ->description('Rubric category: `Correctness`, `Security`, `Data`, `Compatibility`, `Ticket Alignment`, `Tests`, `Performance`, or `Conventions`.'),
            'body' => $schema->string()->required()
                ->description('Full markdown body of the comment, including any ```original and ```suggestion fences copied byte for byte.'),
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
            'signals' => $schema->object([
                'impact' => $this->signalSchema($schema),
                'blast_radius' => $this->signalSchema($schema),
                'behavior_change' => $this->signalSchema($schema),
                'verification_strength' => $this->signalSchema($schema),
                'context_completeness' => $this->signalSchema($schema),
                'model_confidence' => $schema->integer()->required(),
                'uncertainties' => $schema->array()->items($schema->string())->required(),
                'human_review_reasons' => $schema->array()->items($schema->string())->required(),
            ])->required(),
            'risk' => $schema->string()->enum(['low', 'high', 'unknown'])->required(),
            'summary' => $schema->string()->required()
                ->description('Reviewer-facing notes: the `## For the reviewer` section as markdown (what the PR does, ticket coverage, risk areas with suggested questions, what was and was not verified).'),
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

    private function signalSchema(JsonSchema $schema): Type
    {
        return $schema->object([
            'value' => $schema->integer()->required(),
            'explanation' => $schema->string()->required(),
            'references' => $schema->array()->items($schema->string())->required(),
        ])->required();
    }
}
