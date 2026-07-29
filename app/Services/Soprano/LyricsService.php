<?php

namespace App\Services\Soprano;

use App\Models\TrackMeta;

/**
 * Backfills track lyrics using LRCLIB, a keyless, no-auth public API:
 *
 *   track_meta (title) + track->artist (name) + track->album (title) + duration
 *      └─ LRCLIB /api/get (exact match) ─► plainLyrics
 *
 * Only the exact /api/get endpoint is used (no /api/search fallback) to keep
 * matches tight and avoid attaching the wrong lyrics. Both the plain text and
 * the timestamped syncedLyrics (LRC) are stored when present, so the track page
 * can offer a karaoke-style synced view. Each row is stamped lyrics_checked_at
 * after an attempt so misses aren't retried every run. Rows that already have
 * lyrics (e.g. embedded tags picked up during sync) are left untouched and just
 * stamped. LRCLIB asks for a descriptive User-Agent and reasonable request
 * rates, so calls are throttled.
 */
class LyricsService
{
    private const USER_AGENT = 'Soprano/1.0 ( https://soprano.williamhleucka.com )';
    private const LRCLIB_GET = 'https://lrclib.net/api/get';
    private const THROTTLE_SECONDS = 1;

    private const SKIP_ARTISTS = ['Various Artists', 'Unknown Artist'];

    /**
     * Process track_meta rows not checked yet (or, with $recheck, those still
     * missing lyrics). Always stamps lyrics_checked_at after an attempt.
     *
     * @return object{success:bool,checked:int,found:int,missed:int,failed:int,error:?string}
     */
    public function backfill(int $limit = 0, bool $recheck = false): object
    {
        $checked = 0;
        $found   = 0;
        $missed  = 0;
        $failed  = 0;
        $success = true;
        $error   = null;

        try {
            $query = TrackMeta::query();
            $recheck ? $query->whereNull('lyrics') : $query->whereNull('lyrics_checked_at');
            $rows = $query->get($limit);

            foreach ($rows as $meta) {
                $checked++;
                try {
                    // Don't overwrite lyrics already present (e.g. from file tags).
                    if (trim((string) ($meta->lyrics ?? '')) !== '') {
                        $meta->update(['lyrics_checked_at' => date('Y-m-d H:i:s')]);
                        $found++;
                        continue;
                    }

                    $resolved = $this->resolveLyrics($meta);
                    if ($resolved !== null) {
                        $meta->update([
                            'lyrics'            => $resolved['plain'],
                            'synced_lyrics'     => $resolved['synced'],
                            'lyrics_checked_at' => date('Y-m-d H:i:s'),
                        ]);
                        $found++;
                    } else {
                        $meta->update(['lyrics_checked_at' => date('Y-m-d H:i:s')]);
                        $missed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    error_log(sprintf(
                        '[soprano lyrics] failed for track_meta #%s: %s',
                        $meta->id,
                        $e->getMessage(),
                    ));
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            $error   = $e->getMessage();
        }

        return (object) [
            'success' => $success,
            'checked' => $checked,
            'found'   => $found,
            'missed'  => $missed,
            'failed'  => $failed,
            'error'   => $error,
        ];
    }

    /**
     * Plain + synced (LRC) lyrics from LRCLIB, or null when nothing usable was
     * found. When LRCLIB only returns timestamped lines, the plain text is
     * derived from them so the fallback view still has something to show.
     *
     * @return array{plain:string,synced:?string}|null
     */
    private function resolveLyrics(TrackMeta $meta): ?array
    {
        $track = $meta->track();
        if ($track === null) {
            return null;
        }

        $title    = trim((string) ($meta->title ?? ''));
        $artist   = trim((string) ($track->trackArtist()->name ?? ''));
        $album    = trim((string) ($track->album()->title ?? ''));
        $duration = (int) round(((int) ($meta->length_ms ?? 0)) / 1000);

        // LRCLIB's exact endpoint matches on title + artist + duration; without
        // any of those a "get" is meaningless, so treat it as a miss.
        if ($title === '' || $artist === '' || $duration <= 0) {
            return null;
        }
        if (in_array($artist, self::SKIP_ARTISTS, true)) {
            return null;
        }

        $params = [
            'track_name'  => $title,
            'artist_name' => $artist,
            'duration'    => (string) $duration,
        ];
        if ($album !== '') {
            $params['album_name'] = $album;
        }

        $data = $this->httpJson(self::LRCLIB_GET . '?' . http_build_query($params));
        if ($data === null) {
            return null;
        }

        $plain  = $data['plainLyrics'] ?? null;
        $synced = $data['syncedLyrics'] ?? null;

        $plain  = is_string($plain) && trim($plain) !== '' ? $plain : null;
        $synced = is_string($synced) && trim($synced) !== '' ? $synced : null;

        // Some LRCLIB entries carry only timestamped lines; derive the plain
        // fallback from them so there's always readable text to store.
        if ($plain === null && $synced !== null) {
            $plain = $this->stripTimestamps($synced);
        }
        if ($plain === null) {
            return null;
        }

        return ['plain' => $plain, 'synced' => $synced];
    }

    /** Strip leading [mm:ss.xx] LRC tags, yielding plain lyric text. */
    private function stripTimestamps(string $lrc): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $lrc) as $line) {
            $text = trim(preg_replace('/\[\d+:\d+(?:\.\d+)?\]/', '', $line));
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        return implode("\n", $lines);
    }

    /** @return array<string,mixed>|null */
    private function httpJson(string $url): ?array
    {
        $body = $this->httpGet($url, ['Accept: application/json']);
        sleep(self::THROTTLE_SECONDS);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<int,string> $headers */
    private function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        return $body;
    }
}
