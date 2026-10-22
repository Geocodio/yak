<?php

namespace App\Channels\Slack;

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Block Kit payloads for a change held for a co-owner. Pure: every method
 * takes the request and returns blocks or text. Area names, rationale, paths
 * and names are untrusted and go through SlackText::escape.
 */
class CoOwnerBlocks
{
    public const string APPROVE_ACTION_ID = 'yak_coowner_approve';

    public const string REJECT_ACTION_ID = 'yak_coowner_reject';

    public const string WITHDRAW_ACTION_ID = 'yak_coowner_withdraw';

    public const string TAG_ACTION_PREFIX = 'yak_coowner_tag:';

    public const string DECLINE_ACTION_PREFIX = 'yak_coowner_decline:';

    private const string EARLY_WARNING_CONTEXT = 'Working on it now · this is a guess based on your request';

    private const array RISK_RANK = ['critical' => 4, 'high' => 3, 'unknown' => 2, 'medium' => 1, 'low' => 0];

    /**
     * @return list<array<string, mixed>>
     */
    public static function threadHeld(CoOwnerRequest $request): array
    {
        [$headline, $details, $asked] = self::threadHeldParagraphs($request);
        $branch = (string) $request->task->branch_name;

        return [
            self::section("*{$headline}*"),
            self::section($details . "\n\n" . $asked),
            self::context('CI passed on ' . SlackText::escape($branch)),
            ['type' => 'actions', 'elements' => [
                self::linkButton('View changes', 'yak_link_view_changes', self::compareUrl($request)),
                self::linkButton('Open task', 'yak_link_open_task', self::taskUrl($request)),
                ['type' => 'button', 'action_id' => self::WITHDRAW_ACTION_ID, 'value' => (string) $request->id, 'text' => self::plain('Withdraw')],
            ]],
            self::context(self::heldSince($request)),
        ];
    }

    /**
     * The early warning thread reply with the co-owner picker and decline button.
     *
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     * @return list<array<string, mixed>>
     */
    public static function earlyWarning(YakTask $task, array $warning): array
    {
        $fallback = $warning['fallbackName'] === null ? 'No thanks, ask later' : 'No thanks, ask ' . $warning['fallbackName'] . ' later';

        return [
            self::section(self::earlyWarningText($warning)),
            ['type' => 'actions', 'elements' => [
                [
                    'type' => 'users_select',
                    'action_id' => self::TAG_ACTION_PREFIX . $task->id,
                    'placeholder' => self::plain('Pick a co-owner'),
                ],
                ['type' => 'button', 'action_id' => self::DECLINE_ACTION_PREFIX . $task->id, 'value' => (string) $task->id, 'text' => self::plain(Str::limit($fallback, 70, ''))],
            ]],
            self::context(self::EARLY_WARNING_CONTEXT),
        ];
    }

    /**
     * The early warning after a co-owner was picked: no controls.
     *
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     * @return list<array<string, mixed>>
     */
    public static function earlyWarningTagged(array $warning, string $coOwnerName): array
    {
        return self::earlyWarningResolved($warning, '@' . SlackText::escape($coOwnerName) . ' will be asked if the change is held.');
    }

    /**
     * The early warning after the requester declined to tag anyone.
     *
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     * @return list<array<string, mixed>>
     */
    public static function earlyWarningDeclined(array $warning): array
    {
        $who = $warning['fallbackName'] === null ? 'a co-owner' : SlackText::escape($warning['fallbackName']);

        return self::earlyWarningResolved($warning, "Yak will ask {$who} if the change is held.");
    }

    /**
     * The first sentences of the warning, for Slack and for other channels' notifications.
     *
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     */
    public static function earlyWarningText(array $warning, bool $shouldEscape = true): string
    {
        $escape = fn (string $text): string => $shouldEscape ? SlackText::escape($text) : $text;
        $areas = Arr::join(array_map(fn (array $area): string => $escape($area['name']) . " ({$area['risk']} risk)", $warning['areas']), ', ', ' and ');

        return "On it. Heads up: this will likely touch {$areas}, so it'll need a co-owner before I open a PR. Want to tag someone now? Anyone with write access to " . $escape($warning['repoName']) . ' can do it.';
    }

    /**
     * Reads a button or picker action id of the early warning.
     *
     * @return array{kind: 'tag'|'decline', taskId: int}|null
     */
    public static function parseEarlyWarningAction(string $actionId): ?array
    {
        foreach (['tag' => self::TAG_ACTION_PREFIX, 'decline' => self::DECLINE_ACTION_PREFIX] as $kind => $prefix) {
            if (str_starts_with($actionId, $prefix) && ctype_digit(substr($actionId, strlen($prefix)))) {
                return ['kind' => $kind, 'taskId' => (int) substr($actionId, strlen($prefix))];
            }
        }

        return null;
    }

    /**
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     * @return list<array<string, mixed>>
     */
    private static function earlyWarningResolved(array $warning, string $outcome): array
    {
        return [
            self::section(self::earlyWarningText($warning) . "\n\n" . $outcome),
            self::context(self::EARLY_WARNING_CONTEXT),
        ];
    }

    /**
     * The three paragraphs of the held thread reply.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function threadHeldParagraphs(CoOwnerRequest $request, bool $shouldEscape = true): array
    {
        $escape = fn (string $text): string => $shouldEscape ? SlackText::escape($text) : $text;
        $repository = $escape($request->repository->github_full_name);
        $areas = $request->matched_areas;

        $rationale = $escape(self::riskiestArea($request)['rationale'] ?? '');
        $details = self::holdSentence($request)
            ?? 'It touches ' . self::areaList($areas, $escape) . '.' . ($rationale !== '' ? " {$rationale}" : '')
                . " Changes to areas like this get a second person with write access to {$repository}, whoever asks for them.";

        $asked = $request->asked_user_ids === []
            ? "Nobody could be asked yet: no CODEOWNERS owner or fallback owner resolves to a linked user with write access. Anyone with write access to {$repository} can approve from the task page."
            : "I've asked " . Arr::join(array_map($escape, self::askedNames($request)), ', ', ' and ') . ". Anyone with write access to {$repository} can pick it up, so feel free to share the task link.";

        return ['Your change is built and CI passed. It needs a co-owner before I open a PR.', $details, $asked];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function directMessage(CoOwnerRequest $request, User $recipient): array
    {
        $requester = self::requesterName($request);
        $first = SlackText::escape(self::firstName($request));
        $repository = SlackText::escape($request->repository->github_full_name);
        $holdSentence = self::holdSentence($request);
        $risk = self::riskiestArea($request)['risk'] ?? 'unknown';
        $reason = $holdSentence ?? "It touches an area the {$repository} risk profile rates {$risk}, so a second person with write access takes a look before a PR opens.";

        return [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => Str::limit($requester, 100) . ' needs a co-owner for a change']],
            self::section("Yak finished a task for {$first} and CI passed. {$reason} Would you co-own it?"),
            ['type' => 'section', 'fields' => [
                self::field('Task', self::taskLink($request)),
                $holdSentence !== null
                    ? self::field('Why it is held', self::truncate($holdSentence . "\n" . self::heldFilesText($request)))
                    : self::field('Matched area', self::truncate(self::matchedAreaText($request))),
                self::field('Requested by', SlackText::escape($requester)),
                self::field('Repository', $repository),
                self::field('Changes', self::changesText($request)),
                self::field('CI', 'Passed'),
            ]],
            self::section('<' . self::compareUrl($request) . '|View changes on GitHub>'),
            self::decisionButtons($request),
            self::context(self::heldSince($request) . ' · Approving makes you the responsible reviewer'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function reminder(CoOwnerRequest $request): array
    {
        $first = SlackText::escape(self::firstName($request));
        $repository = SlackText::escape($request->repository->github_full_name);
        $area = SlackText::escape((string) (self::riskiestArea($request)['name'] ?? 'Unchecked files'));
        $risk = ucfirst(self::riskiestArea($request)['risk'] ?? 'unknown');
        $requester = SlackText::escape(self::requesterName($request));

        return [
            self::section("Still waiting: {$first}'s change to {$repository} has been held for 2 days."),
            self::section('*Task*  ' . self::taskLink($request)),
            self::section("{$area} ● {$risk}  ·  Requested by {$requester}  ·  ✓ CI passed  ·  +{$request->additions} −{$request->deletions}"),
            self::decisionButtons($request),
            self::context('Expires in 5 days'),
        ];
    }

    public static function reminderNoteText(CoOwnerRequest $request, bool $shouldEscape = true): string
    {
        if ($request->asked_user_ids === []) {
            $repository = $shouldEscape ? SlackText::escape($request->repository->github_full_name) : $request->repository->github_full_name;

            return "Still waiting for a co-owner. Nobody could be asked yet, so anyone with write access to {$repository} can approve from the task page. The request expires in 5 days.";
        }

        $names = array_map(fn (string $name): string => $shouldEscape ? SlackText::escape($name) : $name, self::askedNames($request));

        return 'Still waiting for a co-owner. I asked ' . Arr::join($names, ', ', ' and ') . ' again. The request expires in 5 days.';
    }

    /**
     * The DM after the request resolves, with no decision buttons.
     *
     * @return list<array<string, mixed>>
     */
    public static function resolved(CoOwnerRequest $request, User $recipient): array
    {
        $name = $request->decidedBy !== null ? SlackText::escape($request->decidedBy->name) : null;
        $nameOrFallback = $name ?? 'A co-owner';
        $time = self::time($request->decided_at ?? now(), 'g:i A');
        $first = SlackText::escape(self::firstName($request));
        $task = $request->task;

        if ($request->status === CoOwnerRequestStatus::Pending) {
            return self::directMessage($request, $recipient);
        }

        if ($request->status === CoOwnerRequestStatus::Approved) {
            $number = $task->pullRequestNumber();
            $isApprover = $request->decided_by_user_id === $recipient->id;
            $text = $isApprover
                ? "Approved by {$nameOrFallback}. PR #{$number} opened, you're the responsible reviewer."
                : "Approved by {$nameOrFallback}. PR #{$number} is open.";

            $blocks = [self::section($text)];

            if ($task->pr_url !== null && $task->pr_url !== '') {
                $blocks[] = ['type' => 'actions', 'elements' => [self::linkButton("View PR #{$number}", 'yak_link_view_pr', $task->pr_url)]];
            }

            return [...$blocks, self::context("Approved {$time} · {$first} has been told in the original thread")];
        }

        return [self::section(match ($request->status) {
            CoOwnerRequestStatus::Rejected => "Rejected by {$nameOrFallback} at {$time}. The branch was deleted and no PR was opened.",
            CoOwnerRequestStatus::Withdrawn => ($name !== null ? "Withdrawn by {$name} at {$time}." : "Withdrawn at {$time}.") . ' No action needed. The branch was deleted and no PR was opened.',
            CoOwnerRequestStatus::ChangesRequested => "{$nameOrFallback} requested changes at {$time}. Yak is running a follow-up and will ask again.",
            CoOwnerRequestStatus::Expired => "Expired. Nobody decided within 7 days, so the task was closed and no PR was opened. If you still want it, {$first} can ask Yak again in the thread.",
            default => 'Replaced by a newer run of this task. No action needed.',
        })];
    }

    /**
     * The note posted in the original thread once the request resolves.
     * Empty when the status has none (an approval goes through the Result
     * notification).
     */
    public static function resolvedNoteText(CoOwnerRequest $request, bool $shouldEscape = true): ?string
    {
        $name = $request->decidedBy !== null ? $request->decidedBy->name : 'A co-owner';
        $name = $shouldEscape ? SlackText::escape($name) : $name;
        $branch = $shouldEscape ? SlackText::escape((string) $request->task->branch_name) : (string) $request->task->branch_name;

        return match ($request->status) {
            CoOwnerRequestStatus::Rejected => "{$name} rejected this change, so no PR was opened. The branch was deleted.",
            CoOwnerRequestStatus::ChangesRequested => "{$name} asked for changes. I'm working on them and will ask again.",
            CoOwnerRequestStatus::Expired => "This request expired after 7 days without a co-owner. The task is closed. Nobody approved or rejected it, so no PR was opened. The branch {$branch} is kept for now.",
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function threadResolved(CoOwnerRequest $request): array
    {
        $text = self::resolvedNoteText($request);

        if ($text === null) {
            return [];
        }

        $blocks = [self::section($text)];

        if ($request->status === CoOwnerRequestStatus::Expired) {
            $blocks[] = self::section('To pick it up again, ask me in this thread, for example @Yak try this again, and I will start a new run and hold it for a co-owner.');
            $blocks[] = ['type' => 'actions', 'elements' => [self::linkButton('Open task', 'yak_link_open_task', self::taskUrl($request))]];
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function threadReminder(CoOwnerRequest $request): array
    {
        return [self::section(self::reminderNoteText($request))];
    }

    /**
     * The plain text of the first block, for Slack's notification fallback.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function fallbackText(array $blocks): string
    {
        return strip_tags((string) ($blocks[0]['text']['text'] ?? 'Yak'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decisionButtons(CoOwnerRequest $request): array
    {
        $id = (string) $request->id;

        return ['type' => 'actions', 'elements' => [
            ['type' => 'button', 'style' => 'primary', 'action_id' => self::APPROVE_ACTION_ID, 'value' => $id, 'text' => self::plain('Approve & open PR')],
            self::linkButton('Request changes', 'yak_link_request_changes', self::taskUrl($request) . '#co-owner'),
            [
                'type' => 'button',
                'style' => 'danger',
                'action_id' => self::REJECT_ACTION_ID,
                'value' => $id,
                'text' => self::plain('Reject'),
                'confirm' => [
                    'title' => self::plain('Reject this change?'),
                    'text' => ['type' => 'plain_text', 'text' => 'The branch is deleted and no PR is opened.'],
                    'confirm' => self::plain('Reject'),
                    'deny' => self::plain('Cancel'),
                ],
            ],
        ]];
    }

    private static function matchedAreaText(CoOwnerRequest $request): string
    {
        $newPaths = collect($request->changed_files)->where('status', 'new')->pluck('path')->all();

        return collect($request->matched_areas)->map(function (array $area) use ($newPaths): string {
            $paths = collect($area['paths'])->take(10)->map(
                fn (string $path): string => '`' . SlackText::escape($path) . '`' . (in_array($path, $newPaths, true) ? ' new' : '')
            )->implode("\n");

            return trim(SlackText::escape($area['name']) . " ({$area['risk']})\n" . SlackText::escape(Str::limit($area['rationale'], 300)) . "\n{$paths}");
        })->implode("\n\n") ?: 'Files that could not be checked';
    }

    /**
     * Keeps a field under Slack's 2000 character limit.
     */
    private static function truncate(string $text): string
    {
        return Str::limit($text, 1900);
    }

    private static function heldFilesText(CoOwnerRequest $request): string
    {
        return collect($request->changed_files)->where('isHeld', true)->take(10)
            ->map(fn (array $file): string => '`' . SlackText::escape($file['path']) . '`')->implode("\n");
    }

    /**
     * The reason for a hold that has no held area, or null when areas matched.
     */
    private static function holdSentence(CoOwnerRequest $request): ?string
    {
        return match (true) {
            $request->hold_reasons === ['floor'] => "It changes Yak's own config files, which always need a co-owner.",
            $request->matched_areas === [] => 'Yak could not check every changed file against the risk profile, so it holds the change to be safe.',
            default => null,
        };
    }

    private static function changesText(CoOwnerRequest $request): string
    {
        $files = $request->file_count . ($request->file_count === 1 ? ' file' : ' files');

        return "+{$request->additions} −{$request->deletions} across {$files}";
    }

    /**
     * @param  array<int, array{name: string, risk: string, rationale: string, paths: array<int, string>}>  $areas
     */
    private static function areaList(array $areas, callable $escape): string
    {
        return Arr::join(array_map(fn (array $area): string => $escape($area['name']) . " ({$area['risk']} risk)", $areas), ', ', ' and ');
    }

    /**
     * @return array{name: string, risk: string, rationale: string, paths: array<int, string>}|null
     */
    private static function riskiestArea(CoOwnerRequest $request): ?array
    {
        $riskiest = null;

        foreach ($request->matched_areas as $area) {
            if ($riskiest === null || (self::RISK_RANK[$area['risk']] ?? 0) > (self::RISK_RANK[$riskiest['risk']] ?? 0)) {
                $riskiest = $area;
            }
        }

        return $riskiest;
    }

    /**
     * The asked users' names in the order they were asked.
     *
     * @return array<int, string>
     */
    private static function askedNames(CoOwnerRequest $request): array
    {
        $users = $request->askedUsers()->keyBy('id');

        return collect($request->asked_user_ids)->map(fn (int $id): ?string => $users->get($id)?->name)->filter()->values()->all();
    }

    private static function requesterName(CoOwnerRequest $request): string
    {
        $starter = $request->task->startedBy;

        return $starter !== null ? $starter->name : ($request->task->author_name ?? 'Someone');
    }

    private static function firstName(CoOwnerRequest $request): string
    {
        return Str::before(self::requesterName($request), ' ');
    }

    private static function heldSince(CoOwnerRequest $request): string
    {
        return 'Held since ' . self::time($request->held_at, 'H:i') . ' · expires in 7 days';
    }

    private static function time(\DateTimeInterface $moment, string $format): string
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone'))->format($format);
    }

    private static function taskUrl(CoOwnerRequest $request): string
    {
        return rtrim((string) config('app.url'), '/') . '/tasks/' . $request->task_id;
    }

    private static function taskLink(CoOwnerRequest $request): string
    {
        $title = SlackText::escape(Str::limit($request->task->headline(), 80), true);

        return '<' . self::taskUrl($request) . "|#{$request->task_id} {$title}>";
    }

    public static function compareUrl(CoOwnerRequest $request): string
    {
        $encode = fn (string $ref): string => implode('/', array_map('rawurlencode', explode('/', $ref)));

        return 'https://github.com/' . $encode($request->repository->github_full_name) . '/compare/'
            . $encode($request->repository->default_branch) . '...' . $encode((string) $request->task->branch_name);
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $text): array
    {
        return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(string $text): array
    {
        return ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $text]]];
    }

    /**
     * @return array<string, string>
     */
    private static function field(string $label, string $value): array
    {
        return ['type' => 'mrkdwn', 'text' => "*{$label}*\n{$value}"];
    }

    /**
     * @return array<string, string>
     */
    private static function plain(string $text): array
    {
        return ['type' => 'plain_text', 'text' => $text];
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkButton(string $label, string $actionId, string $url): array
    {
        return ['type' => 'button', 'action_id' => $actionId, 'url' => $url, 'text' => self::plain($label)];
    }
}
