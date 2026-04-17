<?php

namespace App\Services;

/**
 * Perceptual image hashing for screenshot dedup.
 *
 * Uses dHash (difference hash): the image is scaled to 9×8 grayscale and
 * each row produces 8 bits by comparing adjacent pixels. The 64-bit result
 * is encoded as a 16-char hex string. Two images are perceptually similar
 * when their Hamming distance is small — on a 64-bit hash, 0 means the
 * scene is identical at low resolution and ≤ 2 tolerates minor compression
 * noise without folding in meaningful UI state changes.
 */
class PerceptualHash
{
    /**
     * Compute a 16-char hex dHash for the given image file.
     * Returns null if the file can't be decoded (unknown format, corrupt, etc).
     */
    public static function dhash(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $bytes = (string) @file_get_contents($path);
        if ($bytes === '' || @getimagesizefromstring($bytes) === false) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y) & 0xFF;
                $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                $bits .= ($left < $right) ? '1' : '0';
            }
        }

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }

        return str_pad($hex, 16, '0', STR_PAD_LEFT);
    }

    /**
     * Hamming distance between two hex-encoded dHashes.
     * Returns PHP_INT_MAX if either argument is not a valid 16-char hex string.
     */
    public static function hamming(string $hexA, string $hexB): int
    {
        if (strlen($hexA) !== 16 || strlen($hexB) !== 16) {
            return PHP_INT_MAX;
        }
        if (! ctype_xdigit($hexA) || ! ctype_xdigit($hexB)) {
            return PHP_INT_MAX;
        }

        $a = hex2bin($hexA);
        $b = hex2bin($hexB);
        $x = $a ^ $b;
        $d = 0;
        for ($i = 0, $n = strlen($x); $i < $n; $i++) {
            $d += substr_count(str_pad(decbin(ord($x[$i])), 8, '0', STR_PAD_LEFT), '1');
        }

        return $d;
    }
}
