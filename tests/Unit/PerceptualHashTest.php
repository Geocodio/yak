<?php

use App\Services\PerceptualHash;

function makePng(string $path, int $width, int $height, callable $paint): void
{
    $img = imagecreatetruecolor($width, $height);
    $paint($img, $width, $height);
    imagepng($img, $path);
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/yak-phash-' . bin2hex(random_bytes(4));
    mkdir($this->tmp, 0755, true);
});

afterEach(function () {
    if (is_dir($this->tmp)) {
        foreach (glob($this->tmp . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }
});

test('dhash returns a 16-char hex string for a valid PNG', function () {
    $path = $this->tmp . '/solid.png';
    makePng($path, 100, 100, function ($img, $w, $h) {
        $red = imagecolorallocate($img, 200, 50, 50);
        imagefilledrectangle($img, 0, 0, $w, $h, $red);
    });

    $hash = PerceptualHash::dhash($path);

    expect($hash)->toBeString()
        ->and(strlen($hash))->toBe(16)
        ->and($hash)->toMatch('/^[0-9a-f]{16}$/');
});

test('dhash returns null for a missing file', function () {
    expect(PerceptualHash::dhash($this->tmp . '/nope.png'))->toBeNull();
});

test('dhash returns null for garbage bytes', function () {
    $path = $this->tmp . '/garbage.png';
    file_put_contents($path, 'not-an-image');

    expect(PerceptualHash::dhash($path))->toBeNull();
});

test('identical images produce identical hashes', function () {
    $a = $this->tmp . '/a.png';
    $b = $this->tmp . '/b.png';
    $paint = function ($img, $w, $h) {
        $bg = imagecolorallocate($img, 240, 230, 210);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        $fg = imagecolorallocate($img, 50, 50, 150);
        imagefilledrectangle($img, 20, 20, 80, 40, $fg);
    };
    makePng($a, 200, 150, $paint);
    makePng($b, 200, 150, $paint);

    $hashA = PerceptualHash::dhash($a);
    $hashB = PerceptualHash::dhash($b);

    expect(PerceptualHash::hamming($hashA, $hashB))->toBe(0);
});

test('visually different images produce distant hashes', function () {
    $a = $this->tmp . '/left.png';
    $b = $this->tmp . '/right.png';
    makePng($a, 200, 150, function ($img, $w, $h) {
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        $fg = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, $fg);
    });
    makePng($b, 200, 150, function ($img, $w, $h) {
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        $fg = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, (int) ($w / 2), 0, $w, $h, $fg);
    });

    $hashA = PerceptualHash::dhash($a);
    $hashB = PerceptualHash::dhash($b);

    expect(PerceptualHash::hamming($hashA, $hashB))->toBeGreaterThan(10);
});

test('hamming returns PHP_INT_MAX for malformed hashes', function () {
    expect(PerceptualHash::hamming('short', 'also-short'))->toBe(PHP_INT_MAX)
        ->and(PerceptualHash::hamming('zzzzzzzzzzzzzzzz', '0000000000000000'))->toBe(PHP_INT_MAX);
});
