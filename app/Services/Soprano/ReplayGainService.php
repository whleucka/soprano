<?php

namespace App\Services\Soprano;

use App\Models\Track;
use App\Models\TrackFeature;

/**
 * Loudness-normalization gain for a track, in dB. Sync already stores the
 * ReplayGain track gain tag when the file carries one; tracks without tags
 * fall back to the Essentia avg_loudness_db feature, so the whole library
 * normalizes — not just the tagged two-thirds.
 *
 * The gain is applied in two places (see TranscodeService and player.js):
 * baked into the cached Opus transcode for lossless sources, and applied
 * client-side via a WebAudio gain node for files that stream as-is.
 */
class ReplayGainService
{
    // ReplayGain tags and Essentia's avg_loudness_db measure loudness against
    // different references. Across the library's dual-signal tracks (both a
    // tag and a feature row) the offset rg_gain + avg_loudness_db sits at
    // -24.2 dB with a stddev of ~1 dB, so this constant maps Essentia
    // loudness onto the same scale the tags use.
    private const ESSENTIA_REF_DB = -24.2;

    // Track peaks aren't stored, so boosts are capped conservatively to keep
    // quiet masters from clipping; cuts are always safe and get more range.
    private const MAX_BOOST_DB = 6.0;
    private const MAX_CUT_DB   = -24.0;

    /** Gain to reach reference loudness, clamped; 0.0 when nothing is known. */
    public function trackGainDb(Track $track): float
    {
        $gain = $this->tagGainDb($track) ?? $this->essentiaGainDb($track);
        if ($gain === null) {
            return 0.0;
        }

        return round(max(self::MAX_CUT_DB, min(self::MAX_BOOST_DB, $gain)), 2);
    }

    /** Parse the stored ReplayGain tag ("-8.4", "+1.20 dB", …). */
    private function tagGainDb(Track $track): ?float
    {
        $raw = trim((string) ($track->meta()?->replaygain_track_gain ?? ''));
        if ($raw === '' || !preg_match('/^[+-]?\d+(\.\d+)?/', $raw, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    private function essentiaGainDb(Track $track): ?float
    {
        $db = TrackFeature::where('track_id', $track->id)->first()?->avg_loudness_db;

        return $db === null ? null : self::ESSENTIA_REF_DB - (float) $db;
    }

    /**
     * Unix timestamp of the gain input a cached encode can be older than, or
     * null when there isn't one.
     *
     * TranscodeService bakes the gain in at encode time and judges freshness by
     * mtime, so a gain that only becomes known *after* the encode is invisible
     * — the cache goes on playing flat forever. Only the Essentia fallback can
     * move that way: features are extracted long after the file lands (the
     * library's own backfill ran for five weeks) while the file itself never
     * changes. A ReplayGain *tag* can't, because re-tagging rewrites the source
     * and bumps its mtime, which the existing freshness check already catches.
     */
    public function essentiaStampFor(Track $track): ?int
    {
        if ($this->tagGainDb($track) !== null) {
            return null;
        }

        $row = TrackFeature::where('track_id', $track->id)->first();
        if ($row === null || $row->avg_loudness_db === null) {
            return null;
        }

        $stamp = strtotime((string) ($row->updated_at ?: $row->created_at));

        return $stamp === false ? null : $stamp;
    }
}
