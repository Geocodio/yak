<?php

test('login page shows a visible google sign in button with no javascript errors', function () {
    $page = visit(route('login'));

    $page->assertVisible('[data-testid="google-signin"]')
        ->assertNoJavaScriptErrors();
});
