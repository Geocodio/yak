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

it('rejects an animate element that targets xlink:href with a javascript: URI', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
      <a>
        <text x="10" y="20">click me</text>
        <animate attributeName="xlink:href" to="javascript:alert(document.domain)" begin="0s" dur="1s" fill="freeze"/>
      </a>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects a set element that targets href', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
      <a>
        <text x="10" y="20">click me</text>
        <set attributeName="href" to="javascript:alert(document.domain)" begin="0s"/>
      </a>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects an animateTransform element that targets an on* handler', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
      <rect width="10" height="10">
        <animateTransform attributeName="onclick" to="alert(1)" begin="0s"/>
      </rect>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('accepts an animateMotion element with no attributeName', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg">
      <rect width="10" height="10">
        <animateMotion path="M0,0 L10,10" begin="0s" dur="2s"/>
      </rect>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeTrue();
});

it('rejects an aliased xlink namespace prefix used to disguise attributeName="href"', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:evil="http://www.w3.org/1999/xlink">
      <a>
        <text x="10" y="20">click me</text>
        <animate attributeName="evil:href" to="javascript:alert(document.domain)" begin="0s" dur="1s" fill="freeze"/>
      </a>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});

it('rejects a whitespace-padded, mixed-case aliased attributeName', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:Evil="http://www.w3.org/1999/xlink">
      <a>
        <text x="10" y="20">click me</text>
        <animate attributeName=" Evil:HREF " to="javascript:alert(document.domain)" begin="0s" dur="1s" fill="freeze"/>
      </a>
    </svg>
    SVG;

    expect(new SvgLogoValidator)->isSafe($svg)->toBeFalse();
});
