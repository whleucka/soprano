<?php

namespace App\Services\Soprano;

class CoverArtService
{
    public function dominantColor(?string $coverUrl): ?array
    {
        if (!$coverUrl) {
            return null;
        }

        $publicPrefix = config("soprano.public_covers");
        if (!str_starts_with($coverUrl, $publicPrefix)) {
            return null;
        }

        $filename = substr($coverUrl, strlen($publicPrefix));
        $path = rtrim(config("soprano.covers_path"), '/') . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        $data = @file_get_contents($path);
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

        if (empty($buckets)) {
            return null;
        }

        $top = null;
        foreach ($buckets as $bucket) {
            if ($top === null || $bucket['count'] > $top['count']) {
                $top = $bucket;
            }
        }

        return [
            (int) ($top['r'] / $top['count']),
            (int) ($top['g'] / $top['count']),
            (int) ($top['b'] / $top['count']),
        ];
    }
}
