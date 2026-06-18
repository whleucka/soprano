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
    private string $ffprobe;
    /** @var array<string,bool> lowercased source extensions that always transcode */
    private array $sourceFormats;
    /** @var array<string,bool> container extensions that get probed by codec */
    private array $probeFormats;
    /** @var array<string,bool> audio codecs that warrant transcoding when probed */
    private array $losslessCodecs;

    public function __construct()
    {
        $this->cachePath     = rtrim((string) config('soprano.transcode_path'), '/');
        $this->bitrate       = (string) config('soprano.transcode_bitrate');
        $this->ffmpeg        = (string) config('soprano.ffmpeg_bin');
        $this->ffprobe       = (string) config('soprano.ffprobe_bin');
        $this->sourceFormats = array_fill_keys(
            array_map('strtolower', (array) config('soprano.transcode_source_formats')),
            true,
        );
        $this->probeFormats = array_fill_keys(
            array_map('strtolower', (array) config('soprano.transcode_probe_formats')),
            true,
        );
        $this->losslessCodecs = array_fill_keys(
            array_map('strtolower', (array) config('soprano.transcode_lossless_codecs')),
            true,
        );
    }

    /**
     * True when the track's source should be transcoded to Opus. Pure-lossless
     * extensions (flac, wav, …) match outright; MP4-family containers (.m4a/.mp4)
     * are ambiguous — they hold AAC (leave alone) or ALAC/lossless (must encode),
     * so their actual audio codec is probed with ffprobe.
     */
    public function needsTranscode(Track $track): bool
    {
        $ext = strtolower(pathinfo((string) $track->pathname, PATHINFO_EXTENSION));

        if (isset($this->sourceFormats[$ext])) {
            return true;
        }

        if (isset($this->probeFormats[$ext])) {
            return isset($this->losslessCodecs[$this->probeCodec((string) $track->pathname)]);
        }

        return false;
    }

    /**
     * Return the lowercased audio codec name of a file (e.g. "alac", "aac"), or
     * "" if it can't be determined. Used to disambiguate MP4-family containers.
     */
    private function probeCodec(string $src): string
    {
        if (!is_file($src) || !is_readable($src)) {
            return '';
        }

        $cmd = sprintf(
            '%s -v error -select_streams a:0 -show_entries stream=codec_name '
            . '-of default=nokey=1:noprint_wrappers=1 %s 2>/dev/null',
            escapeshellcmd($this->ffprobe),
            escapeshellarg($src),
        );

        return strtolower(trim((string) shell_exec($cmd)));
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
        $src   = (string) $track->pathname;
        $cache = $this->cacheFileFor($track);

        // Fast path: a warmed transcode is served without re-probing the codec,
        // so the common stream request never pays for an ffprobe call.
        if ($this->isFresh($cache, $src)) {
            return $cache;
        }

        if (!$this->needsTranscode($track)) {
            return null;
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
                // High-rate / surround sources (e.g. 5.1 @ 96kHz FLAC) make
                // libopus fail with -22 and write nothing. Normalize into Opus's
                // native domain first: 48kHz (Opus runs at 48k internally) and a
                // stereo downmix (browsers output stereo anyway), which sidesteps
                // the surround channel-mapping path entirely.
                '%s -nostdin -y -hide_banner -loglevel error -i %s '
                . '-vn -map_metadata 0 -ac 2 -ar 48000 -c:a libopus -b:a %s -vbr on -f opus %s 2>&1',
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
