<?php

namespace App\Services\Stacks;

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Enums\EffortLevel;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Exceptions\StackRuleViolation;
use App\Models\Thread;
use App\Services\Teleport\HeldEvents;
use App\Services\Threads\YakToolRouter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Serves the stack tools and passes every other tool to the router it wraps.
 */
class StackToolRouter implements YakToolRouter
{
    public function __construct(
        private readonly YakToolRouter $inner,
        private readonly StackRules $rules,
        private readonly ChildSpawner $spawner,
        private readonly StackMessenger $messenger,
        private readonly HeldEvents $heldEvents,
    ) {}

    public function handle(Thread $thread, YakToolCall $call): YakToolResult
    {
        try {
            return match ($call->tool) {
                'spawn_thread' => $this->spawn($thread, $call->input),
                'send_message' => $this->send($thread, $call->input),
                'list_threads' => $this->list($thread),
                default => $this->inner->handle($thread, $call),
            };
        } catch (StackRuleViolation $violation) {
            return new YakToolResult(true, $violation->getMessage());
        } catch (HttpException $busy) {
            if ($busy->getStatusCode() !== 503) {
                throw $busy;
            }

            return new YakToolResult(true, 'Yak is busy with another change to this stack. Try again in a moment.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function spawn(Thread $thread, array $input): YakToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        $kind = ThreadKind::tryFrom((string) ($input['kind'] ?? ''));

        if ($name === '' || $kind === null) {
            throw new StackRuleViolation('spawn_thread needs a name and a kind of implement, mockup, research or general.');
        }

        $model = isset($input['model']) && is_string($input['model']) && $input['model'] !== '' ? $input['model'] : null;
        $effort = isset($input['effort']) && is_string($input['effort']) ? EffortLevel::tryFrom($input['effort'])?->value : null;
        $child = $this->spawner->spawn($thread, $name, (string) ($input['brief'] ?? ''), $kind, $model, null, isset($input['repo']) && is_string($input['repo']) ? $input['repo'] : null, effort: $effort);
        $this->spawner->requestStart($child);

        return new YakToolResult(false, "Started sub-thread {$child->name} ({$kind->value}).");
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function send(Thread $thread, array $input): YakToolResult
    {
        $text = trim((string) ($input['text'] ?? ''));

        if ($text === '') {
            throw new StackRuleViolation('send_message needs text.');
        }

        $target = $this->rules->resolveTarget($thread, (string) ($input['to'] ?? ''));
        $this->rules->assertMaySend($thread, $target);

        $toParent = $thread->isChild() && $target->id === $thread->parent_thread_id;

        if ($toParent && $this->heldEvents->holdChildQuestion($thread, $text)) {
            return new YakToolResult(false, 'Sent.');
        }

        $this->messenger->send($thread, $target, $text, $toParent ? StackThreadMessageKind::Question : StackThreadMessageKind::Message);

        return new YakToolResult(false, 'Sent.');
    }

    private function list(Thread $thread): YakToolResult
    {
        $lines = array_map(
            fn (array $row): string => "- {$row['name']} ({$row['kind']}, {$row['state']})" . ($row['repo'] === null ? '' : " in {$row['repo']}" . ($row['pullRequestUrl'] === null ? '' : ", {$row['pullRequestUrl']}")) . ($row['latestResult'] === null ? '' : ": {$row['latestResult']}"),
            StackListing::for($thread),
        );

        return new YakToolResult(false, implode("\n", $lines));
    }
}
