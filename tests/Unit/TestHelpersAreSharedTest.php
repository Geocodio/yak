<?php

/**
 * Top-level functions in a test file only exist once Pest has loaded that
 * file, so another file calling them breaks when run alone or in parallel.
 * Shared helpers belong in tests/Helpers.
 */
it('does not share top-level helper functions between test files', function () {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__))) as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    $violations = [];
    foreach ($files as $definingPath => $source) {
        preg_match_all('/^function\s+(\w+)\s*\(/m', $source, $matches);
        foreach ($matches[1] as $name) {
            foreach ($files as $callerPath => $callerSource) {
                if ($callerPath === $definingPath) {
                    continue;
                }
                if (preg_match('/(?<![\w>:$])' . $name . '\s*\(/', $callerSource)) {
                    $violations[] = "{$name}() is defined in {$definingPath} but called from {$callerPath}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
