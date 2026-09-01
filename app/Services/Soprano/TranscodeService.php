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
 *
 * Data saver (per-client, see clients.data_saver) widens that rule: for a
 * client on a marginal link, an already-compressed source is *also* shrunk to
 * Opus whenever the original is meaningfully bigger than the encode would be
 * (worthShrinking). A 320kbps MP3 is ~2.5x the bytes of the 128k Opus, and on
 * a weak cellular link that difference decides whether the track starts at all
 * -- measured: the same phone in one sitting started every Opus track in <=4s
 * and stalled up to 52s on the raw MP3s. The cache file is shared, so
 * entitlement is checked *before* the cache is consulted: a client without the
 * mode never gets a lossy source's encode, only its original.
 *
 * A client can opt out entirely (clients.transcode, on by default): every
 * track then streams from its source file and the player applies ReplayGain
 * itself. That is the setting for someone who wants the lossless bytes and
 * has the link to carry them -- it also switches off data saver in practice,
 * since there is no encode left for it to reach for.
 *
 * ReplayGain is baked into the encode (ffmpeg volume filter), so cached files
 * play at reference loudness with no client-side work; the player applies gain
 * via WebAudio only for sources that stream as-is. Freshness is mtime-based and
 * doesn't see gain changes, so a file encoded before its Essentia loudness row
 * existed stays flat and plays ~10dB hot forever. regain() finds and repairs
 * those (soprano:transcode --regain, run nightly by jobs/soprano_regain.php);
 * soprano:transcode --force re-encodes everything regardless.
 */
class TranscodeService
{
    /**
     * How much bigger than its own Opus encode a lossy source has to be before
     * data saver bothers re-encoding it. Below this the saving doesn't pay for
     * a second lossy generation -- and it keeps the mode from "shrinking" a
     * 96kbps MP3 into a larger 128k Opus.
     */
    public const SHRINK_MARGIN = 1.3;

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

    public function __construct(private ReplayGainService $replaygain)
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
     * True when this client will be served the Opus encode rather than the
     * source file -- lossless always, lossy only under data saver and only
     * when the encode is actually the smaller of the two.
     *
     * $transcode is the client's opt-out (clients.transcode) and outranks
     * everything else: off means the source file, whatever it is. It also
     * neutralises data saver, which has nothing left to shrink to.
     *
     * The gain question rides on this: a track served as Opus has ReplayGain
     * baked in, so the client must apply none of its own. Both the stream and
     * the player payload ask here, so they can't disagree and double it up.
     */
    public function servesOpus(Track $track, bool $dataSaver = false, bool $transcode = true): bool
    {
        if (!$transcode) {
            return false;
        }

        return $this->needsTranscode($track)
            || ($dataSaver && $this->worthShrinking($track));
    }

    /**
     * True when re-encoding an already-compressed source would meaningfully
     * cut what goes over the wire. Compares the real file against what the
     * configured bitrate would produce for the same duration -- the source's
     * own tagged bitrate lies on VBR files and ignores embedded artwork,
     * while filesize is exactly what the client has to download.
     *
     * Unknown duration means no answer, so it returns false: leaving a track
     * on its original is always safe, encoding blind is not.
     */
    private function worthShrinking(Track $track): bool
    {
        $bytes = @filesize((string) $track->pathname);
        if ($bytes === false || $bytes <= 0) {
            return false;
        }

        return self::shrinkWorthwhile(
            (int) $bytes,
            (int) ($track->meta()?->length_ms ?? 0),
            self::parseBitrate($this->bitrate),
        );
    }

    /**
     * The threshold itself, kept static and free of models so it can be unit
     * tested without standing up the container. Unknown duration or bitrate
     * means no answer, so it returns false: leaving a track on its original
     * is always safe, encoding blind is not.
     */
    public static function shrinkWorthwhile(int $bytes, int $ms, int $bitrateBps): bool
    {
        if ($bytes <= 0 || $ms <= 0 || $bitrateBps <= 0) {
            return false;
        }

        $encoded = ($bitrateBps / 8) * ($ms / 1000);

        return $bytes >= $encoded * self::SHRINK_MARGIN;
    }

    /**
     * Parse an ffmpeg bitrate string ("128k", "96000", "1M") into bits per
     * second. Static and public so the threshold can be unit tested without
     * standing up the container.
     */
    public static function parseBitrate(string $bitrate): int
    {
        $value = strtolower(trim($bitrate));
        $scale = 1;

        if (str_ends_with($value, 'k')) {
            $scale = 1000;
            $value = substr($value, 0, -1);
        } elseif (str_ends_with($value, 'm')) {
            $scale = 1000000;
            $value = substr($value, 0, -1);
        }

        return max(0, (int) round(((float) $value) * $scale));
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
     * demand if missing or stale. Returns null when this client should be served
     * the original (see servesOpus) or when encoding failed.
     *
     * One cache file backs both modes, so freshness alone can't decide: a lossy
     * track encoded for a data-saver client leaves a perfectly fresh .opus that
     * everyone else must *not* be given. Hence entitlement first, cache second
     * -- the reverse order silently downgrades every other client to 128k the
     * moment one data-saver listener touches the track.
     */
    public function resolve(Track $track, bool $dataSaver = false, bool $transcode = true): ?string
    {
        // Opted out: never touch the cache, never encode -- not even the fast
        // path below, which would otherwise hand back a warmed file.
        if (!$transcode) {
            return null;
        }

        $src   = (string) $track->pathname;
        $cache = $this->cacheFileFor($track);
        $ext   = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $fresh = $this->isFresh($cache, $src);

        // Fast path, for the cases where entitlement is already settled without
        // opening the file: an extension that proves the source lossless, or a
        // data-saver client, who takes the encode whenever one exists. Both skip
        // the ffprobe that disambiguating an MP4 container would cost.
        if ($fresh && ($dataSaver || isset($this->sourceFormats[$ext]))) {
            return $cache;
        }

        if (!$this->servesOpus($track, $dataSaver, $transcode)) {
            return null;
        }

        if ($fresh) {
            return $cache;
        }

        return $this->transcode($src, $cache, $this->replaygain->trackGainDb($track))
            ? $cache
            : null;
    }

    /** A cache file counts as fresh when it exists and is newer than the source. */
    private function isFresh(string $cache, string $src): bool
    {
        return is_file($cache)
            && filesize($cache) > 0
            && filemtime($cache) >= filemtime($src);
    }

    /**
     * True when a cached encode predates the Essentia loudness row its gain is
     * derived from — it was baked flat (or at a gain since superseded) and the
     * track plays hot until it's re-encoded.
     *
     * A track with no cache file is never "stale": backfill() encodes those,
     * and a track that needs no transcode has no cache file to begin with, so
     * the stat doubles as the needsTranscode() test without paying for ffprobe.
     */
    public function hasStaleGain(Track $track): bool
    {
        $cache = $this->cacheFileFor($track);
        if (!is_file($cache)) {
            return false;
        }

        $stamp = $this->replaygain->essentiaStampFor($track);
        if ($stamp === null) {
            return false;
        }

        $mtime = @filemtime($cache);

        return $mtime !== false && $mtime < $stamp;
    }

    /**
     * Encode $src to Opus at $dest, applying $gainDb of ReplayGain. Writes to a
     * temp file and atomically renames so a half-written file is never served,
     * and holds an flock so concurrent callers don't encode the same track at
     * once.
     *
     * $force re-encodes over a cache file that is fresh by mtime — the case
     * where the gain, not the source, is what changed.
     */
    public function transcode(string $src, string $dest, float $gainDb = 0.0, bool $force = false): bool
    {
        if (!is_file($src) || !is_readable($src)) {
            return false;
        }
        $this->ensureCacheDir();

        // Snapshot before blocking on the lock, so a forced re-encode can tell
        // "a file that was already here" from "a file someone else wrote while
        // we waited". Without $force the freshness test answers both at once;
        // with it, it can't — the stale file we were sent to replace is fresh
        // by mtime and would satisfy the test itself, turning the whole call
        // into a silent no-op.
        $before = @filemtime($dest);

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
            $settled = $force
                ? (($now = @filemtime($dest)) !== false && $now !== $before)
                : $this->isFresh($dest, $src);
            if ($settled) {
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
                . '-vn -map_metadata 0 -ac 2 -ar 48000 %s-c:a libopus -b:a %s -vbr on -f opus %s 2>&1',
                escapeshellcmd($this->ffmpeg),
                escapeshellarg($src),
                // Bake ReplayGain into the cache so the client plays it flat.
                abs($gainDb) >= 0.05
                    ? sprintf('-af %s ', escapeshellarg(sprintf('volume=%.2fdB', $gainDb)))
                    : '',
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

                $this->transcode($src, $cache, $this->replaygain->trackGainDb($track), $force)
                    ? $encoded++
                    : $failed++;
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

    /**
     * Re-encode cached files whose baked-in gain has gone stale (see
     * hasStaleGain), stopping after $limit encodes or $seconds of wall clock,
     * whichever lands first. 0 disables either cap.
     *
     * Once the budget is spent the walk keeps *counting* stale tracks without
     * encoding them, so `remaining` is a real number of tracks left rather than
     * a guess — divide by $limit for the number of nights still to go.
     *
     * Self-terminating and safe to re-run: a re-encode stamps the cache file
     * with the current time, which puts it ahead of the feature row that made
     * it stale, so no track is picked up twice.
     *
     * @return object{success:bool,stale:int,encoded:int,skipped:int,failed:int,remaining:int,error:?string}
     */
    public function regain(int $limit = 0, int $seconds = 0): object
    {
        $stale   = 0;
        $encoded = 0;
        $skipped = 0;
        $failed  = 0;
        $success = true;
        $error   = null;

        $deadline = $seconds > 0 ? time() + $seconds : 0;
        $spent    = false;

        try {
            foreach ($this->staleGainCandidates() as $track) {
                if (!$this->hasStaleGain($track)) {
                    continue;
                }
                $stale++;

                if ($spent) {
                    continue;
                }

                $src = (string) $track->pathname;
                if (!is_file($src)) {
                    // The source went away; sync prunes the row, not this job.
                    $skipped++;
                    continue;
                }

                $this->transcode($src, $this->cacheFileFor($track), $this->replaygain->trackGainDb($track), true)
                    ? $encoded++
                    : $failed++;

                if (($limit > 0 && $encoded + $failed >= $limit)
                    || ($deadline > 0 && time() >= $deadline)) {
                    $spent = true;
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object) [
            'success'   => $success,
            'stale'     => $stale,
            'encoded'   => $encoded,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'remaining' => max(0, $stale - $encoded),
            'error'     => $error,
        ];
    }

    /**
     * Tracks whose gain comes from the Essentia fallback — the only ones whose
     * cached encode can go stale, since a tag-derived gain rides the source
     * file's mtime. Narrowed in SQL so a run doesn't stat the whole library.
     *
     * @return array<int,Track>
     */
    private function staleGainCandidates(): array
    {
        return Track::query()
            ->whereRaw(
                'EXISTS (SELECT 1 FROM track_features tf'
                . ' WHERE tf.track_id = tracks.id AND tf.avg_loudness_db IS NOT NULL)',
            )
            ->whereRaw(
                'NOT EXISTS (SELECT 1 FROM track_meta tm'
                . " WHERE tm.track_id = tracks.id AND COALESCE(tm.replaygain_track_gain, '') <> '')",
            )
            ->orderBy('id')
            ->get();
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
