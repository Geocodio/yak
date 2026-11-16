<?php

use App\Support\PublicId;

it('generates eight lowercase Crockford base32 characters', function () {
    foreach (range(1, 500) as $ignored) {
        $publicId = PublicId::generate();

        expect($publicId)->toHaveLength(8)
            ->and($publicId)->toMatch('/^[0-9a-hjkmnp-tv-z]{8}$/')
            ->and($publicId)->not->toMatch('/[ilou]/')
            ->and(ctype_digit($publicId))->toBeFalse();
    }
});

it('uses the whole alphabet', function () {
    $seen = [];

    foreach (range(1, 400) as $ignored) {
        foreach (str_split(PublicId::generate()) as $character) {
            $seen[$character] = true;
        }
    }

    expect(array_keys($seen))->toHaveCount(strlen(PublicId::ALPHABET));
});
