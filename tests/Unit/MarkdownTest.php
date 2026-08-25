<?php

use App\Support\Markdown;

it('renders markdown to html', function () {
    expect(Markdown::toHtml('A **bold** `call`.'))
        ->toContain('<strong>bold</strong>')
        ->toContain('<code>call</code>');
});

it('strips embedded html and unsafe links', function () {
    $html = Markdown::toHtml('Hi <script>alert(1)</script> [x](javascript:alert(1))');

    expect($html)->not->toContain('<script>')
        ->and($html)->not->toContain('javascript:');
});

it('returns an empty string for blank markdown', function (?string $input) {
    expect(Markdown::toHtml($input))->toBe('')
        ->and(Markdown::toPlainText($input))->toBe('');
})->with([null, '', '   ', "\n\n"]);

it('flattens markdown to a single plain line', function () {
    $text = Markdown::toPlainText("## Summary\n\nRemoves the dead `continue` guards\nand **short-circuits** the scan.");

    expect($text)->toBe('Summary Removes the dead continue guards and short-circuits the scan.');
});

it('decodes entities when flattening', function () {
    expect(Markdown::toPlainText('Penalty <= 0 & "quoted"'))->toBe('Penalty <= 0 & "quoted"');
});

it('limits the flattened text when a limit is given', function () {
    expect(Markdown::toPlainText('**One** two three four', 9))->toBe('One two t...');
});
