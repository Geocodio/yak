<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class InstallScriptController extends Controller
{
    /**
     * Serves the one-command installer. Both values are shell-quoted here and printed
     * unescaped by the view, so Blade's HTML escaping never touches the script.
     */
    public function __invoke(): Response
    {
        $script = view('install', [
            'quotedInstanceUrl' => escapeshellarg(rtrim((string) config('app.url'), '/')),
            'quotedMarketplace' => escapeshellarg((string) config('yak.teleport.marketplace')),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
