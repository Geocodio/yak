<?php

namespace App\Channels\Slack;

use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;

/**
 * Block Kit messages about failed previews and their repairs.
 *
 * Every message is fixed copy. Branch names, the cause the investigation
 * wrote and command output are untrusted and go through SlackText::escape.
 * Each builder returns the plain headline (the notification text) and the
 * blocks.
 */
class PreviewRepairBlocks
{
    /**
     * @param  list<BranchDeployment>  $deployments
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function repairOpened(PreviewRepair $previewRepair, array $deployments): array
    {
        $headline = self::previewsHeadline($deployments, 'failed');
        $pullRequestUrl = (string) $previewRepair->task?->pr_url;

        return self::message($headline, [
            self::section('*' . self::escapedHeadline($deployments, 'failed') . "*\n"
                . 'I looked into it. ' . self::cause($previewRepair) . ' I opened a pull request to fix it: ' . self::pullRequestLink($previewRepair) . "\n"
                . 'Once it merges, I\'ll rebuild ' . (count($deployments) === 1 ? 'your preview.' : 'your previews.')),
            ...self::previewList($deployments),
            self::actions([['View the PR', 'preview_repair_view_pr', $pullRequestUrl], ...self::logButtons($deployments)]),
        ]);
    }

    /**
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function repairOpenedFeed(PreviewRepair $previewRepair, int $failedPreviewCount): array
    {
        $repositoryName = (string) $previewRepair->repository->name;
        $headline = "Previews are broken on {$repositoryName}";

        return self::message($headline, [
            self::section('*' . SlackText::escape($headline) . "*\n"
                . $failedPreviewCount . ' ' . ($failedPreviewCount === 1 ? 'preview' : 'previews') . ' failed since ' . $previewRepair->first_failed_at->format('M j')
                . ' for the same reason: ' . self::cause($previewRepair) . ' Fix: ' . self::pullRequestLink($previewRepair)),
            self::actions([['View the PR', 'preview_repair_view_pr', (string) $previewRepair->task?->pr_url]]),
        ]);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function branchCause(PreviewRepair $previewRepair, array $deployments): array
    {
        return self::message(self::previewsHeadline($deployments, 'failed'), [
            self::section('*' . self::escapedHeadline($deployments, 'failed') . "*\n"
                . 'The cause is in this branch: ' . self::cause($previewRepair)
                . ' The shared preview setup is fine, so I did not open a pull request.'),
            self::actions(self::logButtons($deployments)),
        ]);
    }

    /**
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function unresolvedFeed(PreviewRepair $previewRepair): array
    {
        $headline = "A preview failed on {$previewRepair->repository->name} and I could not fix it";
        $cause = $previewRepair->cause !== null && $previewRepair->cause !== ''
            ? SlackText::escape($previewRepair->cause)
            : 'I could not tell why.';

        return self::message($headline, [
            self::section('*' . SlackText::escape($headline) . "*\n" . $cause),
            self::section(self::codeBlock($previewRepair->error_excerpt)),
        ]);
    }

    /**
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function capReached(Repository $repository, int $dailyCap): array
    {
        $headline = "Preview investigations paused on {$repository->name}";

        return self::message($headline, [
            self::section('*' . SlackText::escape($headline) . "*\n"
                . "Previews keep failing, and I reached today's limit of {$dailyCap} investigations. I'll pick up new failures again tomorrow."),
        ]);
    }

    /**
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function repairPreviewFailed(?string $pullRequestUrl, ?int $pullRequestNumber, string $excerpt): array
    {
        $reference = $pullRequestNumber !== null ? "#{$pullRequestNumber}" : 'the repair pull request';
        $headline = "The fix in {$reference} did not start its own preview";
        $link = $pullRequestUrl !== null && $pullRequestNumber !== null ? "<{$pullRequestUrl}|#{$pullRequestNumber}>" : $reference;

        return self::message($headline, [
            self::section("*The fix in {$link} did not start its own preview*"),
            self::section(self::codeBlock($excerpt)),
        ]);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function reminder(PreviewRepair $previewRepair, int $newFailureCount): array
    {
        $headline = "Previews on {$previewRepair->repository->name} are still failing";

        return self::message($headline, [
            self::section('*' . SlackText::escape($headline) . "*\n"
                . $newFailureCount . ' more ' . ($newFailureCount === 1 ? 'preview' : 'previews') . ' failed since I opened ' . self::pullRequestLink($previewRepair)
                . ' on ' . $previewRepair->created_at->format('M j') . '. Merging it fixes them.'),
            self::actions([['View the PR', 'preview_repair_view_pr', (string) $previewRepair->task?->pr_url]]),
        ]);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public static function rebuilt(PreviewRepair $previewRepair, array $deployments): array
    {
        return self::message(self::previewsHeadline($deployments, 'is rebuilding', 'are rebuilding'), [
            self::section('*' . self::escapedHeadline($deployments, 'is rebuilding', 'are rebuilding') . "*\n"
                . 'The fix in ' . self::pullRequestLink($previewRepair) . ' merged, so I am rebuilding ' . (count($deployments) === 1 ? 'your preview.' : 'your previews.')),
            self::actions(array_map(
                fn (BranchDeployment $deployment): array => ['View the preview', 'preview_repair_view_preview_' . $deployment->id, route('deployments.show', $deployment)],
                array_slice($deployments, 0, 5),
            )),
        ]);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     */
    private static function previewsHeadline(array $deployments, string $singularVerb, ?string $pluralVerb = null): string
    {
        return count($deployments) === 1
            ? "Your preview for {$deployments[0]->branch_name} {$singularVerb}"
            : 'Your previews ' . ($pluralVerb ?? $singularVerb);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     */
    private static function escapedHeadline(array $deployments, string $singularVerb, ?string $pluralVerb = null): string
    {
        return count($deployments) === 1
            ? 'Your preview for `' . SlackText::escape((string) $deployments[0]->branch_name) . "` {$singularVerb}"
            : 'Your previews ' . ($pluralVerb ?? $singularVerb);
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     * @return list<array<string, mixed>>
     */
    private static function previewList(array $deployments): array
    {
        if (count($deployments) < 2) {
            return [];
        }

        $lines = array_map(
            fn (BranchDeployment $deployment): string => '- <' . route('deployments.show', $deployment) . '|`' . SlackText::escape((string) $deployment->branch_name, isLinkText: true) . '`>',
            $deployments,
        );

        return [self::section(implode("\n", $lines))];
    }

    /**
     * @param  list<BranchDeployment>  $deployments
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function logButtons(array $deployments): array
    {
        if (count($deployments) !== 1) {
            return [];
        }

        return [['View the preview logs', 'preview_repair_view_logs', route('deployments.show', $deployments[0])]];
    }

    private static function cause(PreviewRepair $previewRepair): string
    {
        return $previewRepair->cause !== null && $previewRepair->cause !== ''
            ? SlackText::escape($previewRepair->cause)
            : 'I could not pin down the cause.';
    }

    private static function pullRequestLink(PreviewRepair $previewRepair): string
    {
        $task = $previewRepair->task;

        if ($task?->pr_url === null) {
            return 'a pull request';
        }

        $label = $task->pr_number !== null ? "{$previewRepair->repository->github_full_name}#{$task->pr_number}" : 'the pull request';

        return '<' . $task->pr_url . '|' . SlackText::escape($label, isLinkText: true) . '>';
    }

    private static function codeBlock(string $text): string
    {
        return "```\n" . SlackText::escape(mb_substr(trim($text), -2800)) . "\n```";
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    private static function message(string $text, array $blocks): array
    {
        return ['text' => $text, 'blocks' => array_values(array_filter($blocks))];
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $text): array
    {
        return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $buttons
     * @return array<string, mixed>|null
     */
    private static function actions(array $buttons): ?array
    {
        $elements = array_map(
            fn (array $button): array => ['type' => 'button', 'action_id' => $button[1], 'url' => $button[2], 'text' => ['type' => 'plain_text', 'text' => $button[0]]],
            array_values(array_filter($buttons, fn (array $button): bool => $button[2] !== '')),
        );

        return $elements === [] ? null : ['type' => 'actions', 'elements' => $elements];
    }
}
