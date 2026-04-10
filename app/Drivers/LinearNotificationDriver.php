<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

class LinearNotificationDriver implements NotificationDriver
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    /**
     * Post a status update as a comment on the Linear issue.
     */
    public function postStatusUpdate(YakTask $task, string $message): void
    {
        $this->postComment($task, $message);
    }

    /**
     * Post the final result as a comment on the Linear issue.
     */
    public function postResult(YakTask $task, string $summary): void
    {
        $this->postComment($task, $summary);
    }

    /**
     * Post a comment on a Linear issue via GraphQL.
     */
    public function postComment(YakTask $task, string $body): void
    {
        $apiKey = $this->getApiKey();

        $externalId = (string) $task->external_id;

        if ($apiKey === '' || $externalId === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($issueId: String!, $body: String!) { commentCreate(input: { issueId: $issueId, body: $body }) { success } }',
                'variables' => [
                    'issueId' => $externalId,
                    'body' => $body,
                ],
            ]);
    }

    /**
     * Update a Linear issue's workflow state via GraphQL.
     */
    public function updateIssueState(YakTask $task, string $stateId): void
    {
        $apiKey = $this->getApiKey();
        $externalId = (string) $task->external_id;

        if ($apiKey === '' || $externalId === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($issueId: String!, $stateId: String!) { issueUpdate(id: $issueId, input: { stateId: $stateId }) { success } }',
                'variables' => [
                    'issueId' => $externalId,
                    'stateId' => $stateId,
                ],
            ]);
    }

    private function getApiKey(): string
    {
        return (string) config('yak.channels.linear.api_key');
    }
}
