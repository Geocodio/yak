<?php

namespace App\Support;

use App\DataTransferObjects\ClarificationQuestion;

/**
 * Claude attaches shared files to a question with markdown image syntax, `![caption](file.png)`: at the end of
 * the question text for context, and in an option's preview for that option. Only the file name counts, so a
 * path into `.yak-artifacts/shared/` resolves to the same file.
 */
final class QuestionAttachments
{
    private const string PATTERN = '/!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/';

    /**
     * @return list<array{alt: string, name: string}>
     */
    public static function references(string $markdown): array
    {
        preg_match_all(self::PATTERN, $markdown, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => ['alt' => trim($match[1]), 'name' => basename(trim($match[2]))], $matches);
    }

    public static function strip(string $markdown): string
    {
        $text = (string) preg_replace(self::PATTERN, '', $markdown);
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /**
     * Every file the questions reference, in order of first use.
     *
     * @param  list<ClarificationQuestion>  $questions
     * @return list<string>
     */
    public static function fileNames(array $questions): array
    {
        $names = [];

        foreach ($questions as $question) {
            foreach ([$question->question, ...array_column($question->options, 'preview')] as $markdown) {
                array_push($names, ...array_column(self::references($markdown), 'name'));
            }
        }

        return array_values(array_unique($names));
    }
}
