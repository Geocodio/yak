<?php

namespace App\Support;

final class GitHubLanguageColors
{
    private const UNKNOWN = '#9ca3af';

    /**
     * @var array<string, string>
     */
    private const COLORS = [
        'blade' => '#f7523f',
        'c' => '#555555',
        'c#' => '#178600',
        'c++' => '#f34b7d',
        'clojure' => '#db5855',
        'css' => '#663399',
        'dart' => '#00B4AB',
        'dockerfile' => '#384d54',
        'elixir' => '#6e4a7e',
        'go' => '#00ADD8',
        'haskell' => '#5e5086',
        'html' => '#e34c26',
        'java' => '#b07219',
        'javascript' => '#f1e05a',
        'kotlin' => '#A97BFF',
        'lua' => '#000080',
        'makefile' => '#427819',
        'perl' => '#0298c3',
        'php' => '#4F5D95',
        'powershell' => '#012456',
        'python' => '#3572A5',
        'r' => '#198CE7',
        'ruby' => '#701516',
        'rust' => '#dea584',
        'scala' => '#c22d40',
        'shell' => '#89e051',
        'svelte' => '#ff3e00',
        'swift' => '#F05138',
        'typescript' => '#3178c6',
        'vue' => '#41b883',
    ];

    public static function hexFor(?string $language): string
    {
        if ($language === null || $language === '') {
            return self::UNKNOWN;
        }

        return self::COLORS[strtolower($language)] ?? self::UNKNOWN;
    }
}
