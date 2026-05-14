<?php

namespace App\Services\Soprano;

use App\Models\Track;
use App\Models\TrackMeta;
use JamesHeinrich\GetID3\GetID3;

class SyncMetaService
{
    private const CHUNK_SIZE = 1000;
    private const BATCH_SIZE = 500;

    private GetID3 $analyzer;

    public function __construct()
    {
        $this->analyzer = new GetID3();
        $this->analyzer->option_md5_data        = false;
        $this->analyzer->option_md5_data_source = false;
        $this->analyzer->option_sha1_data       = false;
        $this->analyzer->encoding               = 'UTF-8';
    }

    public function sync(bool $refresh = false): object
    {
        $scanned = 0;
        $written = 0;
        $failed  = 0;
        $success = true;
        $error   = null;

        try {
            $lastId     = 0;
            $batch      = [];
            $refreshIds = [];

            while (true) {
                $chunk = $this->loadChunk($lastId, $refresh);
                if (empty($chunk)) {
                    break;
                }

                foreach ($chunk as $row) {
                    $lastId = (int) $row['id'];
                    $scanned++;

                    if (!is_file($row['pathname'])) {
                        $failed++;
                        continue;
                    }

                    $meta = $this->extractMeta($row['pathname']);
                    if ($meta === null) {
                        $failed++;
                        continue;
                    }

                    $meta['track_id'] = (int) $row['id'];
                    $batch[] = $meta;

                    if ($refresh) {
                        $refreshIds[] = (int) $row['id'];
                    }

                    if (count($batch) >= self::BATCH_SIZE) {
                        $written += $this->flush($batch, $refreshIds);
                        $batch = [];
                        $refreshIds = [];
                    }
                }
            }

            if (!empty($batch)) {
                $written += $this->flush($batch, $refreshIds);
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }

        return (object)[
            'success' => $success,
            'scanned' => $scanned,
            'written' => $written,
            'failed'  => $failed,
            'error'   => $error,
        ];
    }

    private function loadChunk(int $lastId, bool $refresh): array
    {
        $query = Track::where('id', '>', (string) $lastId);

        if (!$refresh) {
            $query->whereRaw(
                'NOT EXISTS (SELECT 1 FROM track_meta tm WHERE tm.track_id = tracks.id)'
            );
        }

        return $query
            ->orderBy('id')
            ->select(['id', 'pathname'])
            ->getRaw(self::CHUNK_SIZE);
    }

    private function extractMeta(string $pathname): ?array
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

        $tag = static fn(string $key): string => isset($info['comments'][$key][0])
            ? (string) $info['comments'][$key][0]
            : '';

        $bitrate = $info['bitrate'] ?? 0;
        if (is_numeric($bitrate)) {
            $bitrate = (string) (int) round($bitrate / 1000);
        }

        return [
            'artist'          => $tag('artist'),
            'album'           => $tag('album'),
            'title'           => $tag('title'),
            'genre'           => $tag('genre'),
            'year'            => $tag('year'),
            'track_number'    => $tag('track_number'),
            'playtime_string' => (string) ($info['playtime_string'] ?? ''),
            'bitrate'         => (string) $bitrate,
            'mime_type'       => (string) ($info['mime_type'] ?? ''),
        ];
    }

    private function flush(array $batch, array $refreshIds): int
    {
        $db = db();
        $db->beginTransaction();
        try {
            if (!empty($refreshIds)) {
                $placeholders = implode(',', array_fill(0, count($refreshIds), '?'));
                $db->execute(
                    "DELETE FROM track_meta WHERE track_id IN ($placeholders)",
                    $refreshIds
                );
            }

            TrackMeta::createBulk($batch);
            $db->commit();
            return count($batch);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
