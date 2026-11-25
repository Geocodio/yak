<?php

use App\Channels\Slack\SlackMarkup;

it('turns Slack markup into Markdown', function (string $slack, string $markdown) {
    expect(SlackMarkup::toMarkdown($slack)['text'])->toBe($markdown);
})->with([
    'labelled mention' => ['<@U1|sarah> hi', '@sarah hi'],
    'channel' => ['see <#C1|eng-support>', 'see #eng-support'],
    'broadcast' => ['<!here> heads up', '@here heads up'],
    'user group' => ['<!subteam^S1|@backend> please', '@backend please'],
    'labelled link' => ['<https://example.com|the docs>', '[the docs](https://example.com)'],
    'bare link' => ['<https://example.com/a?b=1>', 'https://example.com/a?b=1'],
    'mail link' => ['<mailto:a@example.com|a@example.com>', 'a@example.com'],
    'escapes' => ['a &lt;b&gt; &amp; c', 'a <b> & c'],
]);

it('names an unknown user "someone" and lists the names it mentions', function () {
    expect(SlackMarkup::toMarkdown('<@U9> and <@U8|ann>'))->toBe(['text' => '@someone and @ann', 'mentions' => ['someone', 'ann']]);
});
