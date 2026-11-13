<?php

use App\Models\Thread;

test('the migration strips attachment tokens from existing thread titles', function () {
    $tokened = Thread::factory()->create(['title' => 'Fix this tooltip on the apikey page [Image #1]']);
    $plain = Thread::factory()->create(['title' => 'Fix the login redirect']);

    (require database_path('migrations/2026_11_13_201803_strip_attachment_tokens_from_thread_titles.php'))->up();

    expect($tokened->refresh()->title)->toBe('Fix this tooltip on the apikey page')
        ->and($plain->refresh()->title)->toBe('Fix the login redirect');
});
