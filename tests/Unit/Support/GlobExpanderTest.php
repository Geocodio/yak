<?php

use App\Support\GlobExpander;

it('returns a glob without braces unchanged', function () {
    expect(GlobExpander::expand('app/**/*.php'))->toBe(['app/**/*.php']);
});

it('expands one group', function () {
    expect(GlobExpander::expand('app/{A,B}.php'))->toBe(['app/A.php', 'app/B.php']);
});

it('expands several groups in stable order', function () {
    expect(GlobExpander::expand('src/{a,b}/{x,y}.php'))
        ->toBe(['src/a/x.php', 'src/a/y.php', 'src/b/x.php', 'src/b/y.php']);
});

it('expands nested groups', function () {
    expect(GlobExpander::expand('src/{a,b/{x,y}}.php'))
        ->toBe(['src/a.php', 'src/b/x.php', 'src/b/y.php']);
});

it('leaves unbalanced braces unchanged', function () {
    expect(GlobExpander::expand('src/{a,b.php'))->toBe(['src/{a,b.php'])
        ->and(GlobExpander::expand('src/a},b.php'))->toBe(['src/a},b.php']);
});

it('throws when the expansion exceeds the cap', function () {
    $glob = '{a,b,c,d,e,f,g}{a,b,c,d,e,f,g,h}';

    expect(fn () => GlobExpander::expand($glob))->toThrow(InvalidArgumentException::class);
});
