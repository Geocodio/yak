<?php

namespace App\Models\Concerns;

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskMode;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;

/**
 * Each time a run stops to ask, a round is appended to `clarification_rounds`:
 * the questions, the run's prose summary, and later the answers. The last
 * round is the current one. Answers stay "awaiting resume" until the resumed
 * run has received them, so a resume interrupted by a deploy gets them again.
 */
trait HasClarificationRounds
{
    /**
     * @return list<array{questions: list<array<string, mixed>>, summary: string, asked_at: string, answers: array<string, array{choices: list<string>, other: string|null}>|null, note: string|null, answered_by: string|null, answered_at: string|null, consumed_at: string|null}>
     */
    public function clarificationRounds(): array
    {
        return $this->clarification_rounds ?? [];
    }

    public function clarificationRoundCount(): int
    {
        return count($this->clarificationRounds());
    }

    /**
     * @param  list<ClarificationQuestion>  $questions
     */
    public function recordClarificationRound(array $questions, string $summary): void
    {
        $rounds = $this->clarificationRounds();
        $rounds[] = [
            'questions' => array_map(fn (ClarificationQuestion $question): array => $question->toArray(), $questions),
            'summary' => $summary,
            'asked_at' => now()->toIso8601String(),
            'answers' => null,
            'note' => null,
            'answered_by' => null,
            'answered_at' => null,
            'consumed_at' => null,
        ];

        $this->update(['clarification_rounds' => $rounds]);
    }

    /**
     * @return list<ClarificationQuestion>
     */
    public function pendingClarificationQuestions(): array
    {
        $current = $this->clarificationRounds()[$this->clarificationRoundCount() - 1] ?? null;

        if ($current === null || $current['answers'] !== null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $question): ?ClarificationQuestion => ClarificationQuestion::fromArray($question),
            $current['questions'],
        )));
    }

    /**
     * @param  array<string, array{choices: list<string>, other: string|null}>  $answers
     */
    public function recordClarificationAnswers(array $answers, ?string $note, string $answeredBy): void
    {
        $rounds = $this->clarificationRounds();
        $last = count($rounds) - 1;

        if ($last < 0) {
            return;
        }

        $rounds[$last]['answers'] = $answers;
        $rounds[$last]['note'] = $note !== null && trim($note) !== '' ? trim($note) : null;
        $rounds[$last]['answered_by'] = $answeredBy;
        $rounds[$last]['answered_at'] = now()->toIso8601String();

        $this->update(['clarification_rounds' => $rounds]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function clarificationAnswersAwaitingResume(): ?array
    {
        $current = $this->clarificationRounds()[$this->clarificationRoundCount() - 1] ?? null;

        if ($current === null || $current['answers'] === null || $current['consumed_at'] !== null) {
            return null;
        }

        return $current;
    }

    public function markClarificationAnswersConsumed(): void
    {
        $rounds = $this->clarificationRounds();
        $last = count($rounds) - 1;

        if ($last < 0 || $rounds[$last]['answers'] === null || $rounds[$last]['consumed_at'] !== null) {
            return;
        }

        $rounds[$last]['consumed_at'] = now()->toIso8601String();
        $this->update(['clarification_rounds' => $rounds]);
    }

    /**
     * The job that runs this task, and so the one that resumes it after answers.
     *
     * @return class-string<RunYakJob|RunFollowUpJob|ResearchYakJob|ResearchFollowUpJob>
     */
    public function agentJobClass(): string
    {
        return $this->claimingJobClass() ?? RunFollowUpJob::class;
    }

    /**
     * Same as agentJobClass(), or null for a code follow-up, whose job does
     * not claim the task and so is not sent through AgentJobDispatcher.
     *
     * @return class-string<RunYakJob|ResearchYakJob|ResearchFollowUpJob>|null
     */
    public function claimingJobClass(): ?string
    {
        $isFollowUp = $this->parent_task_id !== null;

        if ($this->mode === TaskMode::Research) {
            return $isFollowUp ? ResearchFollowUpJob::class : ResearchYakJob::class;
        }

        return $isFollowUp ? null : RunYakJob::class;
    }
}
