<?php

namespace App\Services\Soprano;

use App\Models\TrackMeta;
use JamesHeinrich\GetID3\GetID3;

class CoverArtService
{
    private const CHUNK_SIZE = 500;

    private GetID3 $analyzer;

    public function __construct()
    {
        $this->analyzer = new GetID3();
        $this->analyzer->option_md5_data        = false;
        $this->analyzer->option_md5_data_source = false;
        $this->analyzer->option_sha1_data       = false;
        $this->analyzer->encoding               = 'UTF-8';
    }

    public function fetchCovers(): object
    {
        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $success = true;
        $error   = null;

        try {
            $storagePath = config("soprano.covers_path");
            if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                throw new \RuntimeException("Unable to create covers directory: {$storagePath}");
            }

            $lastId = 0;
            while (true) {
                $chunk = $this->loadChunk($lastId);
                if (empty($chunk)) {
                    break;
                }

                foreach ($chunk as $row) {
                    $lastId = (int) $row['id'];
                    $scanned++;

                    if (empty($row['pathname']) || !is_file($row['pathname'])) {
                        $skipped++;
                        continue;
                    }

                    $picture = $this->extractPicture($row['pathname']);
                    if ($picture === null) {
                        $skipped++;
                        continue;
                    }

                    $hash = md5($picture);
                    $filename = "{$hash}.png";
                    $fullPath = rtrim($storagePath, '/') . '/' . $filename;

                    if (!is_file($fullPath) && !$this->writePng($picture, $fullPath)) {
                        $skipped++;
                        continue;
                    }
                    $public_covers = config("soprano.public_covers");

                    TrackMeta::find((string) $row['id'])
                        ?->update(['cover' => $public_covers.$filename]);

                    $updated++;
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object)[
            'success' => $success,
            'scanned' => $scanned,
            'updated' => $updated,
            'skipped' => $skipped,
            'error'   => $error,
        ];
    }

    private function loadChunk(int $lastId): array
    {
        return TrackMeta::where('track_meta.id', '>', (string) $lastId)
            ->whereRaw("(track_meta.cover IS NULL OR track_meta.cover = '')")
            ->whereRaw("EXISTS (SELECT 1 FROM tracks t WHERE t.id = track_meta.track_id)")
            ->orderBy('track_meta.id')
            ->select([
                'track_meta.id AS id',
                'track_meta.track_id AS track_id',
                '(SELECT pathname FROM tracks t WHERE t.id = track_meta.track_id) AS pathname',
            ])
            ->getRaw(self::CHUNK_SIZE);
    }

    private function extractPicture(string $pathname): ?string
    {
        try {
            $info = $this->analyzer->analyze($pathname);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($info) || isset($info['error'])) {
            return null;
        }

        $this->analyzer->CopyTagsToComments($info);

        $data = $info['comments']['picture'][0]['data']
            ?? $info['id3v2']['APIC'][0]['data']
            ?? $info['id3v2']['PIC'][0]['data']
            ?? null;

        return is_string($data) && $data !== '' ? $data : null;
    }

    private function writePng(string $data, string $fullPath): bool
    {
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return false;
        }

        $ok = imagepng($img, $fullPath);

        return $ok;
    }

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
