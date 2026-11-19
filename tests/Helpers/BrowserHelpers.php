<?php

/**
 * Runs a script in the page that polls until `$ready` is true, then returns `$result`; the layout of a phone settles
 * a moment after a resize or a focus.
 */
function settled(mixed $page, string $ready, string $result): mixed
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80 && !({$ready}); attempt++) { await new Promise((resolve) => setTimeout(resolve, 50)); } return {$result}; })()");
}
