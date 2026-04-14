<?php

namespace App\Facades;

use App\Services\PromptResolver;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string render(string $slug, array<string, mixed> $data = [])
 * @method static string renderDefault(string $slug, array<string, mixed> $data = [])
 * @method static string fileContent(string $slug)
 * @method static array<int, string> validate(string $content, array<string, mixed> $fixture = [])
 *
 * @see PromptResolver
 */
class Prompts extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PromptResolver::class;
    }
}
