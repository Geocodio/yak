<?php

use App\DataTransferObjects\PreviewWakeOutcome;

function wakingHtml(PreviewWakeOutcome $outcome): string
{
    return view('threads.preview.waking', ['outcome' => $outcome])->render();
}

it('has a body with no thread title, repo or branch', function () {
    $html = wakingHtml(PreviewWakeOutcome::waking(['Waking the sandbox']));

    expect($html)->toContain('Waking the sandbox')
        ->not->toContain('data-thread-title');
    expect(preg_match('/\bt-[a-z0-9]{10}\./', $html))->toBe(0);
});

it('polls the host-local status path', function () {
    $html = wakingHtml(PreviewWakeOutcome::waking());

    expect($html)->toContain('/_yak/preview-status')
        ->toContain('250')
        ->toContain('location.reload()');
});

it('shows a free sandbox message while waiting for a slot', function () {
    expect(wakingHtml(PreviewWakeOutcome::waitingForSlot()))->toContain('Waiting for a free sandbox');
});

it('redacts secrets from the failed variant', function () {
    $html = wakingHtml(PreviewWakeOutcome::failing('boot failed with sk-ant-abcdefgh12345678 and Bearer abcdefghijklmnopqrstuvwxyz0123'));

    expect($html)->toContain('data-testid="preview-error"')
        ->toContain('[redacted]')
        ->not->toContain('sk-ant-abcdefgh')
        ->not->toContain('abcdefghijklmnopqrstuvwxyz0123')
        ->not->toContain('__Host-yak_preview');
});
