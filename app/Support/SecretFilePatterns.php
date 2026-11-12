<?php

namespace App\Support;

/**
 * The one list of file names that look like secrets. The Share dialog for a
 * private thread and the teleport-up helper both use it. It reads names only,
 * never file contents.
 */
class SecretFilePatterns
{
    public const array NEVER_PRESELECTED = [
        '.env*', '*.env', '*.env.*', '*.pem', '*.key', '*.p12', '*.pfx', '*.jks', '*.keystore',
        '*.pkcs8', '*.p8', '*.ppk', '*.asc', '*.gpg', 'id_rsa*', 'id_dsa*', 'id_ecdsa*', 'id_ed25519*',
        'credentials*.json', 'auth.json', '.npmrc', '.pypirc', '.netrc', '.git-credentials', '.htpasswd',
        '.dockercfg', '.pgpass', '.my.cnf', 'service-account*.json', 'client_secret*.json', '*.tfvars',
        '*.tfstate*', '*.kdbx', 'kubeconfig', 'secrets.yml', 'secrets.yaml', 'secrets.json', 'secrets.*.yml',
        'vault-password*', '.vault_pass*', '.aws/credentials', '.ssh/*', '.gnupg/*', '*.bak', '*.bak-*',
        '*.orig', '*.sqlite', '*.sql.gz',
    ];

    public const array NAME_CONTAINS = [
        'secret', 'credential', 'token', 'passwd', 'password', 'apikey', 'api_key', 'api-key',
    ];

    /** An empty string stands for "no extension". */
    public const array NAME_CONTAINS_EXTENSIONS = [
        '', 'json', 'txt', 'yml', 'yaml', 'env', 'csv', 'ini', 'conf', 'cfg', 'toml', 'xml', 'properties', 'tsv', 'bak',
    ];

    public const array NEVER_FLAGGED = [
        '*.example', '*.sample', '*.example.*', '*.sample.*', '.env.template', '.env.dist',
        '*.d.ts', '*.md', '*.mdx', '*.pub', '*.crt', '*.cer',
    ];

    /** @var array<string, string> */
    private static array $regexCache = [];

    public static function matches(string $path): bool
    {
        $path = self::normalise($path);

        if ($path === '') {
            return false;
        }

        $basename = basename($path);

        foreach (self::NEVER_FLAGGED as $pattern) {
            if (preg_match(self::regex($pattern), $basename) === 1) {
                return false;
            }
        }

        foreach (self::NEVER_PRESELECTED as $pattern) {
            $subject = str_contains($pattern, '/') ? $path : $basename;

            if (preg_match(self::regex($pattern), $subject) === 1) {
                return true;
            }
        }

        $dot = strrpos($basename, '.');
        $extension = $dot === false || $dot === 0 ? '' : substr($basename, $dot + 1);

        if (! in_array($extension, self::NAME_CONTAINS_EXTENSIONS, true)) {
            return false;
        }

        foreach (self::NAME_CONTAINS as $word) {
            if (str_contains($basename, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $containedPaths
     */
    public static function matchesCollapsedDirectory(string $directory, array $containedPaths = []): bool
    {
        $directory = self::normalise($directory);

        if ($directory !== '' && (self::matches($directory) || self::matches($directory . '/_'))) {
            return true;
        }

        foreach ($containedPaths as $path) {
            if (self::matches($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function filter(array $paths): array
    {
        return array_values(array_unique(array_filter($paths, self::matches(...))));
    }

    /**
     * Lowercased, forward slashes, no empty or dot segments, dot-dot resolved.
     */
    private static function normalise(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', strtolower($path))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function regex(string $glob): string
    {
        return self::$regexCache[$glob] ??= (function () use ($glob): string {
            $isPath = str_contains($glob, '/');
            $trailingAny = str_ends_with($glob, '/*');
            $body = $trailingAny ? substr($glob, 0, -2) : $glob;
            $quoted = str_replace('\*', '[^/]*', preg_quote($body, '#'));

            return '#' . ($isPath ? '(^|/)' : '^') . $quoted . ($trailingAny ? '/.+' : '') . '$#i';
        })();
    }
}
