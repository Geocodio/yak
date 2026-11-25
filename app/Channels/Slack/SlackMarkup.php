<?php

namespace App\Channels\Slack;

/**
 * Turns Slack's message markup into Markdown both Claude and the thread can
 * read: user mentions become `@Name`, channel and group mentions their
 * names, `<url|label>` links Markdown links, and Slack's escaped `&`, `<`
 * and `>` plain characters again.
 */
class SlackMarkup
{
    /**
     * @return array{text: string, mentions: list<string>}
     */
    public static function toMarkdown(string $text): array
    {
        $mentions = [];

        $text = (string) preg_replace_callback('/<@([A-Z0-9_]+)(?:\|([^>]+))?>/', function (array $match) use (&$mentions): string {
            $name = ($match[2] ?? '') !== '' ? $match[2] : (UserNameResolver::resolve($match[1]) ?? 'someone');
            $mentions[] = $name;

            return "@{$name}";
        }, $text);

        $text = (string) preg_replace(
            [
                '/<#[A-Z0-9]+\|([^>]*)>/',
                '/<#[A-Z0-9]+>/',
                '/<!(here|channel|everyone)(?:\|[^>]*)?>/',
                '/<!subteam\^[A-Z0-9]+\|([^>]+)>/',
                '/<(https?:\/\/[^|>]+)\|([^>]+)>/',
                '/<mailto:[^|>]+\|([^>]+)>/',
                '/<(https?:\/\/[^>]+)>/',
            ],
            ['#$1', '#channel', '@$1', '$1', '[$2]($1)', '$1', '$1'],
            $text,
        );

        return [
            'text' => str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $text),
            'mentions' => array_values(array_unique($mentions)),
        ];
    }
}
