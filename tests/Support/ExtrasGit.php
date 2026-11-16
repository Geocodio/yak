<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Process;

class ExtrasGit
{
    public static function seedBare(string $directory, string $name): string
    {
        $bare = "{$directory}/{$name}.git";
        $seed = "{$directory}/{$name}-seed";
        mkdir($seed, 0777, true);
        $identity = ['-c', 'user.name=Seed', '-c', 'user.email=seed@example.com'];

        Process::path($directory)->run(['git', 'init', '--bare', '-b', 'main', $bare])->throw();
        Process::path($seed)->run(['git', 'init', '-b', 'main'])->throw();
        file_put_contents("{$seed}/README.md", "{$name}\n");
        Process::path($seed)->run(['git', 'add', '-A'])->throw();
        Process::path($seed)->run(['git', ...$identity, 'commit', '-m', 'Initial commit'])->throw();
        Process::path($seed)->run(['git', 'push', $bare, 'main'])->throw();

        return $bare;
    }
}
