<?php

namespace App\Services\Soprano;

use App\Models\Track;
use FilesystemIterator;

/**
 * Transcodes lossless/large source files (FLAC, WAV, …) to Opus once and caches
 * the result, keyed by track hash, under storage/transcode. The cached file is
 * served statically via X-Accel-Redirect — small, range-friendly, and decoded
 * reliably by every modern browser (unlike large FLACs streamed over HTTP,
 * which Chrome/Safari frequently stall on).
 *
 * Already-compressed formats (mp3, aac, ogg, opus, …) are left alone and stream
 * straight from disk; needsTranscode() returns false for them.
 *
 * The cache is warmed ahead of time by the soprano:transcode command / scheduled
 * job. resolve() also transcodes on demand as a fallback, guarded by an flock so
 * two concurrent stream requests never encode the same track twice.
 */
class TranscodeService
{
    private string $cachePath;
    private string $bitrate;
    private string $ffmpeg;
    /** @var array<string,bool> lowercased source extensions that get transcoded */
    private array $sourceFormats;

    public function __construct()
    {
        $this->cachePath     = rtrim((string) config('soprano.transcode_path'), '/');
        $this->bitrate       = (string) config('soprano.transcode_bitrate');
        $this->ffmpeg        = (string) config('soprano.ffmpeg_bin');
        $this->sourceFormats = array_fill_keys(
            array_map('strtolower', (array) config('soprano.transcode_source_formats')),
            true,
        );
    }

    /** True when the track's source format should be transcoded to Opus. */
    public function needsTranscode(Track $track): bool
    {
        $ext = strtolower(pathinfo((string) $track->pathname, PATHINFO_EXTENSION));
        return isset($this->sourceFormats[$ext]);
    }

    /** Absolute path of the cached Opus file for a track (may not exist yet). */
    public function cacheFileFor(Track $track): string
    {
        return $this->cachePath . '/' . $track->hash . '.opus';
    }

    /**
     * Return the path to a ready Opus transcode for this track, encoding it on
     * demand if missing or stale. Returns null when the track needs no transcode
     * (caller should serve the original) or when encoding failed.
     */
    public function resolve(Track $track): ?string
    {
        if (!$this->needsTranscode($track)) {
            return null;
        }

        $src   = (string) $track->pathname;
        $cache = $this->cacheFileFor($track);

        if ($this->isFresh($cache, $src)) {
            return $cache;
        }

        return $this->transcode($src, $cache) ? $cache : null;
    }

    /** A cache file counts as fresh when it exists and is newer than the source. */
    private function isFresh(string $cache, string $src): bool
    {
        return is_file($cache)
            && filesize($cache) > 0
            && filemtime($cache) >= filemtime($src);
    }

    /**
     * Encode $src to Opus at $dest. Writes to a temp file and atomically renames
     * so a half-written file is never served, and holds an flock so concurrent
     * callers don't encode the same track at once.
     */
    public function transcode(string $src, string $dest): bool
    {
        if (!is_file($src) || !is_readable($src)) {
            return false;
        }
        $this->ensureCacheDir();

        $lock = @fopen($dest . '.lock', 'c');
        if ($lock === false) {
            return false;
        }

        try {
            // Block until we hold the lock; another request may be mid-encode.
            if (!flock($lock, LOCK_EX)) {
                return false;
            }

            // The winner of the lock race may have just produced the file.
            if ($this->isFresh($dest, $src)) {
                return true;
            }

            $tmp = $dest . '.tmp';
            $cmd = sprintf(
                '%s -nostdin -y -hide_banner -loglevel error -i %s '
                . '-vn -map_metadata 0 -c:a libopus -b:a %s -vbr on -f opus %s 2>&1',
                escapeshellcmd($this->ffmpeg),
                escapeshellarg($src),
                escapeshellarg($this->bitrate),
                escapeshellarg($tmp),
            );

            // Encoding a long track can exceed the default time limit; this runs
            // before the X-Accel header is emitted, so let it finish.
            @set_time_limit(0);

            $output = [];
            $status = 0;
            exec($cmd, $output, $status);

            if ($status !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
                @unlink($tmp);
                error_log(sprintf(
                    '[soprano transcode] ffmpeg failed (%d) for %s: %s',
                    $status,
                    $src,
                    implode(' ', array_slice($output, -3)),
                ));
                return false;
            }

            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($dest . '.lock');
        }
    }

    /**
     * Warm the cache for tracks that need transcoding but have no fresh file
     * yet, and prune cache files whose track no longer exists.
     *
     * @return object{success:bool,checked:int,encoded:int,skipped:int,failed:int,pruned:int,error:?string}
     */
    public function backfill(int $limit = 0, bool $force = false): object
    {
        $checked = 0;
        $encoded = 0;
        $skipped = 0;
        $failed  = 0;
        $pruned  = 0;
        $success = true;
        $error   = null;

        try {
            $this->ensureCacheDir();

            foreach (Track::query()->get($limit) as $track) {
                if (!$this->needsTranscode($track)) {
                    continue;
                }
                $checked++;

                $src   = (string) $track->pathname;
                $cache = $this->cacheFileFor($track);

                if (!is_file($src)) {
                    $skipped++;
                    continue;
                }
                if (!$force && $this->isFresh($cache, $src)) {
                    $skipped++;
                    continue;
                }

                $this->transcode($src, $cache) ? $encoded++ : $failed++;
            }

            $pruned = $this->pruneOrphans();
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object) [
            'success' => $success,
            'checked' => $checked,
            'encoded' => $encoded,
            'skipped' => $skipped,
            'failed'  => $failed,
            'pruned'  => $pruned,
            'error'   => $error,
        ];
    }

    /** Delete cached .opus files whose track hash is no longer in the library. */
    private function pruneOrphans(): int
    {
        if (!is_dir($this->cachePath)) {
            return 0;
        }

        $hashes = array_fill_keys(Track::query()->pluck('hash'), true);
        $pruned = 0;

        foreach (new FilesystemIterator($this->cachePath, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'opus') {
                continue;
            }
            $hash = $file->getBasename('.opus');
            if (!isset($hashes[$hash]) && @unlink($file->getPathname())) {
                $pruned++;
            }
        }

        return $pruned;
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cachePath)
            && !mkdir($this->cachePath, 0775, true)
            && !is_dir($this->cachePath)
        ) {
            throw new \RuntimeException("Unable to create transcode cache directory: {$this->cachePath}");
        }
    }
}
