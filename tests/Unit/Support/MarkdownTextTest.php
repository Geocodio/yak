<?php

use App\Support\MarkdownText;

it('collapses newlines and tabs into one line', function () {
    expect(MarkdownText::inline("a\n\n  b\tc "))->toBe('a b c');
});

it('escapes markdown and html metacharacters', function () {
    expect(MarkdownText::inline('a | <!-- b --> # `c` *d*'))
        ->toBe('a \| \<\!\-\- b \-\-\> \# \`c\` \*d\*');
});

it('keeps an injected heading on one line', function () {
    expect(MarkdownText::inline("x\n## Approved"))->toBe('x \#\# Approved');
});

it('truncates with an ellipsis', function () {
    expect(MarkdownText::inline(str_repeat('a', 300), 10))->toBe(str_repeat('a', 10) . '…');
});

it('renders a code span without backticks, newlines or table breaks', function () {
    expect(MarkdownText::code("a`b\nc|d <!--"))->toBe('`a\'b c\|d <!--`');
});

it('neutralises mentions', function () {
    expect(MarkdownText::inline('ping @org/team'))->not->toContain('@o')->toContain("@\u{200B}org");
});
