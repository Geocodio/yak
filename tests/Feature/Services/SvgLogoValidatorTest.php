<?php

use App\Services\SvgLogoValidator;

it('accepts a plain, benign svg', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="40" fill="blue" />
        <use href="#nonexistent" />
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeTrue();
});

it('rejects malformed xml', function (): void {
    expect(new SvgLogoValidator)->isSafe('<svg><circle></svg>')->toBeFalse();
});

it('rejects a script element', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
        <script>alert(document.cookie)</script>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects an onload attribute', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)">
        <circle cx="50" cy="50" r="40" />
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects a foreignObject element', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
        <foreignObject>
            <body xmlns="http://www.w3.org/1999/xhtml">
                <script>alert(document.cookie)</script>
            </body>
        </foreignObject>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects an external href', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
        <a href="https://evil.example/">
            <circle cx="50" cy="50" r="40" />
        </a>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects an external xlink:href', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <use xlink:href="https://evil.example/payload.svg#x" />
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});
