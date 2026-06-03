<?php

use App\Channels\GitHub\FollowUpCommentParser;

beforeEach(function () {
    config()->set('yak.followup.github_prefixes', '/yak,@yak-bot[bot],yak:');
});

function parse(string $body): ?string
{
    return app(FollowUpCommentParser::class)->parse($body);
}

test('parses each configured prefix and strips it', function () {
    expect(parse('/yak handle the empty-state'))->toBe('handle the empty-state')
        ->and(parse('@yak-bot[bot] rename this method'))->toBe('rename this method')
        ->and(parse('yak: add a test for the 401 case'))->toBe('add a test for the 401 case');
});

test('prefix match is case-insensitive', function () {
    expect(parse('/YAK fix the thing'))->toBe('fix the thing')
        ->and(parse('Yak: do the thing'))->toBe('do the thing');
});

test('captures multi-line instructions after the prefix', function () {
    expect(parse("/yak do A\nand also B"))->toBe("do A\nand also B");
});

test('ignores leading blank lines before the prefix line', function () {
    expect(parse("\n\n/yak after blanks"))->toBe('after blanks');
});

test('returns null when no prefix matches', function () {
    expect(parse('looks good to me'))->toBeNull()
        ->and(parse('the /yak tool is great'))->toBeNull()   // prefix not at line start
        ->and(parse(''))->toBeNull()
        ->and(parse('   '))->toBeNull();
});

test('returns null when the prefix has no instructions after it', function () {
    expect(parse('/yak'))->toBeNull()
        ->and(parse('/yak    '))->toBeNull()
        ->and(parse('yak:'))->toBeNull();
});
