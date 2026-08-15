<?php

namespace App\Channels\GitHub;

class FollowUpCommentParser
{
    /**
     * Return the instructions from a PR comment addressed to Yak via a
     * configured prefix (case-insensitive, anchored to the first non-empty
     * line), or null if the comment isn't for Yak / has no instructions.
     */
    public function parse(string $body): ?string
    {
        $trimmed = ltrim($body);

        if ($trimmed === '') {
            return null;
        }

        foreach ($this->prefixes() as $prefix) {
            if ($prefix === '') {
                continue;
            }

            if (mb_strtolower(mb_substr($trimmed, 0, mb_strlen($prefix))) === mb_strtolower($prefix)) {
                $instructions = trim(mb_substr($trimmed, mb_strlen($prefix)));

                return $instructions === '' ? null : $instructions;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function prefixes(): array
    {
        $raw = (string) config('yak.followup.github_prefixes', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $p): bool => $p !== ''));
    }
}
