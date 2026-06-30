<?php

namespace App\Services\Soprano;

class CoverArtService
{
    public function computeDominantHex(string $imagePath): ?string
    {
        if (!is_file($imagePath)) {
            return null;
        }

        $data = @file_get_contents($imagePath);
        if ($data === false) {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $size = 32;
        $small = imagecreatetruecolor($size, $size);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
        unset($src);

        $buckets = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Skip near-black and near-white pixels so the dominant
                // color reflects the actual artwork, not letterboxing.
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max < 25 || $min > 230) {
                    continue;
                }

                $key = (($r >> 3) << 10) | (($g >> 3) << 5) | ($b >> 3);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $buckets[$key]['count']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }

        unset($small);

        if (empty($buckets)) {
            return null;
        }

        $top = null;
        foreach ($buckets as $bucket) {
            if ($top === null || $bucket['count'] > $top['count']) {
                $top = $bucket;
            }
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) ($top['r'] / $top['count']),
            (int) ($top['g'] / $top['count']),
            (int) ($top['b'] / $top['count']),
        );
    }

    public function hexToRgb(?string $hex): ?array
    {
        if ($hex === null || !preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
            return null;
        }
        $int = hexdec($m[1]);
        return [($int >> 16) & 0xFF, ($int >> 8) & 0xFF, $int & 0xFF];
    }
}
