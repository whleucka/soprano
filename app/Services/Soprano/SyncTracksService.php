<?php

namespace App\Services\Soprano;

use App\Models\Track;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SyncTracksService
{
    private const ALLOWED_EXTENSIONS = [
        'mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'wav', 'wma', 'webm',
    ];

    private const BATCH_SIZE = 500;

    public function sync(string $path): object
    {
        $scanned = 0;
        $inserted = 0;
        $skipped = 0;
        $success = true;
        $error = null;

        try {
            $existing = $this->loadExistingHashes();
            $batch = [];

            foreach ($this->iterateMediaFiles($path) as $file) {
                $scanned++;
                $pathname = $file->getPathname();
                $hash = md5($pathname);

                if (isset($existing[$hash])) {
                    $skipped++;
                    continue;
                }

                $batch[] = [
                    'hash' => $hash,
                    'filename' => $file->getFilename(),
                    'pathname' => $pathname,
                ];
                $existing[$hash] = true;

                if (count($batch) >= self::BATCH_SIZE) {
                    $inserted += $this->flush($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $inserted += $this->flush($batch);
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }

        return (object)[
            'success'  => $success,
            'scanned'  => $scanned,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'error'    => $error,
        ];
    }

    private function iterateMediaFiles(string $path): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            yield $file;
        }
    }

    private function flush(array $batch): int
    {
        $db = db();
        $db->beginTransaction();
        try {
            Track::createBulk($batch);
            $db->commit();
            return count($batch);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function loadExistingHashes(): array
    {
        $rows = (new Track())->select(['hash'])->getRaw();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['hash']] = true;
        }
        return $map;
    }
}
