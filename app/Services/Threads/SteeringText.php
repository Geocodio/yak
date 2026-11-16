<?php

namespace App\Services\Threads;

use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Services\TaskAttachmentStager;

/**
 * The text a steering message reaches the runner with. A message from a
 * person is wrapped so the agent knows it arrived mid-turn, and carries its
 * staged attachments. Messages Yak framed itself pass through unchanged.
 */
class SteeringText
{
    public const string PREAMBLE = "The person you are working for sent this message while you were working. Take it into account from here on:\n\n";

    public static function for(Thread $thread, PendingSteeringMessage $message): string
    {
        if ($message->source !== 'thread') {
            return (string) $message->text;
        }

        $text = self::PREAMBLE . $message->text;
        $attachments = $message->attachments()->get()->all();

        if ($attachments === []) {
            return $text;
        }

        $staged = (new TaskAttachmentStager(ThreadShell::for($thread)))->stage((string) $thread->sandbox_container, $attachments);

        return $staged === '' ? $text : $text . "\n\n" . $staged;
    }
}
