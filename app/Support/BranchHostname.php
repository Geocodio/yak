<?php

namespace App\Support;

final class BranchHostname
{
    private const MAX_LABEL_LENGTH = 63;

    private const HASH_SUFFIX_LENGTH = 6;

    private const COLLISION_SUFFIX_LENGTH = 4;

    public static function sanitize(string $raw): string
    {
        $lower = strtolower($raw);
        $replaced = preg_replace('/[^a-z0-9-]/', '-', $lower);
        $collapsed = preg_replace('/-+/', '-', $replaced);

        return trim($collapsed, '-');
    }

    public static function build(string $repoSlug, string $branchName, string $suffix): string
    {
        $label = self::sanitize($repoSlug) . '-' . self::sanitize($branchName);

        if (strlen($label) > self::MAX_LABEL_LENGTH) {
            $hash = substr(hash('sha256', $repoSlug . '/' . $branchName), 0, self::HASH_SUFFIX_LENGTH);
            $keep = self::MAX_LABEL_LENGTH - self::HASH_SUFFIX_LENGTH - 1;
            $label = substr($label, 0, $keep) . '-' . $hash;
        }

        return $label . '.' . $suffix;
    }

    public static function withCollisionSuffix(string $repoSlug, string $branchName, string $suffix): string
    {
        $base = self::build($repoSlug, $branchName, $suffix);
        [$label, $rest] = explode('.', $base, 2);
        $collisionHash = substr(hash('sha256', $repoSlug . '|' . $branchName), 0, self::COLLISION_SUFFIX_LENGTH);

        return $label . '-' . $collisionHash . '.' . $rest;
    }
}
