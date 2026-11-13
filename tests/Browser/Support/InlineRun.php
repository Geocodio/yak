<?php

use App\Models\TaskLog;
use App\Models\YakTask;

/**
 * Seeds the activity of a setup run: reads, a failing then retried
 * `composer install`, thinking steps and the build. `running` stops on an
 * unfinished `git push`; `failed` ends on a second `npm ci` that cannot
 * authenticate; `finished` completes every step.
 */
function seedInlineRun(YakTask $task, string $shape = 'finished'): void
{
    $clock = now()->subMinutes(13);

    $add = function (string $message, array $metadata, string $level = 'info') use ($task, &$clock): void {
        TaskLog::create(['yak_task_id' => $task->id, 'attempt_number' => 1, 'level' => $level, 'message' => $message, 'metadata' => $metadata, 'created_at' => $clock->copy()]);
        $clock = $clock->addSeconds(13);
    };
    $say = fn (string $text) => $add($text, ['type' => 'assistant']);
    $think = fn (string $text) => $add($text, ['type' => 'assistant', 'thinking' => true]);
    $read = fn (string $path, string $output) => $add("📄 Reading `{$path}`", ['type' => 'tool_use', 'tool' => 'Read', 'input' => ['file_path' => $path], 'output' => $output, 'is_error' => false, 'duration_ms' => 100]);
    $run = function (string $command, string $output, int $ms, int $exit = 0) use ($add): void {
        $add("⚡ {$command} → exit {$exit}", ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => $command], 'output' => $output, 'is_error' => $exit !== 0, 'exit_code' => $exit, 'duration_ms' => $ms], $exit !== 0 ? 'warning' : 'info');
    };

    $add('Task picked up', ['type' => 'status']);
    $say("I'll start by reading how the project expects to be run.");
    $read('README.md', "# Geocodio website\n\nLaravel app. Local development runs through Docker Compose.\n\n  docker compose up -d\n  composer install\n  npm ci && npm run build");
    $read('docker-compose.yml', "services:\n  mariadb:\n    image: mariadb:11\n  redis:\n    image: redis:7-alpine");
    $read('composer.json', "\"require\": {\n  \"php\": \"^8.4\",\n  \"ext-gd\": \"*\"\n}");
    $read('package.json', "\"scripts\": {\n  \"build\": \"vite build\"\n}");
    $read('.env.example', "APP_ENV=local\nDB_HOST=mariadb\nREDIS_HOST=redis");
    $read('phpunit.xml', '<env name="DB_DATABASE" value="website_testing"/>');
    $run('grep -nE "^(DB|REDIS|APP)_" .env.example', "1:APP_ENV=local\n9:DB_HOST=mariadb", 200);
    $think("No task binary; I'll run the documented docker compose commands directly.");
    $say('It is a Laravel 12 app on PHP 8.4, with MariaDB and Redis in Docker Compose. Bringing the services up next.');
    $run('cp .env.example .env', '(no output)', 100);
    $run('sudo -E docker compose up -d mariadb redis', " Container website-redis-1    Started\n Container website-mariadb-1  Started", 18000);
    $run('composer install --no-interaction --prefer-dist', "Your lock file does not contain a compatible set of packages.\n\n  Problem 1\n    - intervention/image 3.9.1 requires ext-gd * -> it is missing from your system.", 6000, 2);
    $think('The base image has no gd extension. Install php8.4-gd, then run composer again.');
    $run('sudo apt-get install -y php8.4-gd', 'Setting up php8.4-gd (8.4.3-1) ...', 11000);
    $run('composer install --no-interaction --prefer-dist', "Package operations: 118 installs, 0 updates, 0 removals\nGenerating optimized autoload files", 41000);
    $run('php artisan key:generate', 'Application key set successfully.', 400);
    $run('php artisan migrate --seed --force', "Running migrations.\n  0001_01_01_000000_create_users_table ......... 21ms DONE", 7000);
    $say('Dependencies are in and the database is migrated. Building the assets and running the tests.');

    if ($shape === 'failed') {
        $run('npm ci', "npm error code E401\nnpm error 401 Unauthorized - GET https://npm.pkg.github.com/@geocodio%2fdesign-tokens", 4000, 1);
        $think('401 from the registry. Check for an .npmrc and a token in the environment.');
        $read('.npmrc', "@geocodio:registry=https://npm.pkg.github.com\n//npm.pkg.github.com/:_authToken=\${NPM_TOKEN}");
        $run('printenv | grep -c NPM_TOKEN', '0', 100);
        $run('npm ci', "npm error code E401\nnpm error 401 Unauthorized - GET https://npm.pkg.github.com/@geocodio%2fdesign-tokens", 4000, 1);

        return;
    }

    $run('npm ci', 'added 612 packages in 23s', 23000);

    $run('npm run build', "vite v6.2.0 building for production...\n✓ 412 modules transformed.\n✓ built in 14.21s", 14000);
    $think('The build passes. Two chunk size warnings, unrelated to setup.');
    $run('php artisan test --compact', "Tests:    212 passed (604 assertions)\nDuration: 38.12s", 38000);
    $run('curl -sI http://localhost:8080/up', 'HTTP/1.1 200 OK', 300);
    $run('git add .yak/setup.sh && git commit -m "Add sandbox setup script"', '[yak/sandbox-setup 3f9a2c1] Add sandbox setup script', 400);
    if ($shape === 'running') {
        $add('⚡ git push origin yak/sandbox-setup', ['type' => 'tool_use', 'tool' => 'Bash', 'input' => ['command' => 'git push origin yak/sandbox-setup']]);

        return;
    }

    $run('git push origin yak/sandbox-setup', '* [new branch]      yak/sandbox-setup -> yak/sandbox-setup', 3000);
}
