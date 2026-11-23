<?php

/**
 * Runs a script in the page that polls until `$ready` is true, then returns `$result`; the layout of a phone settles
 * a moment after a resize or a focus.
 */
function settled(mixed $page, string $ready, string $result): mixed
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80 && !({$ready}); attempt++) { await new Promise((resolve) => setTimeout(resolve, 50)); } return {$result}; })()");
}

/**
 * Waits until the selector is in the page (or, with `$isPresent` false, is gone). That is the signal that the part of
 * the page it belongs to has loaded and rendered; the point-in-time assertions (`assertPresent`, `assertVisible`) look
 * once and fail while a request the element depends on is still in flight or being retried.
 */
function waitUntilPresent(mixed $page, string $selector, bool $isPresent = true): void
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);
    $check = ($isPresent ? '' : '!') . "document.querySelector({$quoted})";

    $isSettled = $page->script("(async () => { for (let attempt = 0; attempt < 200 && !({$check}); attempt++) { await new Promise((resolve) => setTimeout(resolve, 50)); } return !!({$check}); })()");

    expect($isSettled)->toBeTrue('Expected [' . $selector . '] to ' . ($isPresent ? 'appear on' : 'leave') . ' the page within 10 seconds.');
}
