<?php

namespace App\Services\Soprano;

/**
 * On-demand mood/activity stations built from audio features (track_features,
 * backfilled by TrackFeaturesService) crossed with per-client affinity:
 *
 *   station feature filter (BPM / danceability / loudness)
 *     × affinity (likes + recency-decayed plays, skips counted against)
 *     + a novelty knob (random jitter) so no two spins are identical
 *
 * Stations are ephemeral — they load straight into the play queue and are
 * never saved as playlists (unlike the nightly AutoPlaylistService mixes).
 * Feature thresholds are provisional, tuned by eyeballing station output
 * against the library; BPM is matched with its half/double octave because
 * the extractor can octave-jump.
 */
class StationService
{
    public function __construct(private MusicService $music)
    {
    }

    private const SIZE = 50;

    /** Max tracks per artist in one deal, so house music doesn't monopolize
     *  Party — danceability concentrates heavily in a few artists. */
    private const ARTIST_CAP = 5;

    /** Weight of the random jitter against the affinity score (likes = 3). */
    private const NOVELTY = 2.0;

    /** Half-life-ish horizon (days) for recency-decayed play scoring. */
    private const DECAY_DAYS = 90.0;

    public const STATIONS = [
        'party' => [
            'name'  => 'Party',
            'icon'  => 'bi-cup-straw',
            'blurb' => 'Danceable and upbeat',
            'where' => '(tf.danceability >= 1.15
                         AND (tf.bpm BETWEEN 95 AND 170 OR tf.bpm / 2 BETWEEN 95 AND 170))',
        ],
        'chill' => [
            'name'  => 'Chill',
            'icon'  => 'bi-moon-stars',
            'blurb' => 'Quieter, low-key listening',
            'where' => '(tf.danceability < 1.05 AND tf.avg_loudness_db <= -15)',
        ],
        'feel-good' => [
            'name'  => 'Feel Good',
            'icon'  => 'bi-sun',
            'blurb' => 'Bright, bouncy, major-key',
            'where' => "(tf.danceability >= 1.2
                         AND tf.key_scale = 'major'
                         AND (tf.bpm BETWEEN 100 AND 165 OR tf.bpm / 2 BETWEEN 100 AND 165)
                         AND tf.avg_loudness_db >= -18)",
        ],
        'full-throttle' => [
            'name'  => 'Full Throttle',
            'icon'  => 'bi-lightning-charge',
            'blurb' => 'Loud and fast',
            'where' => '(tf.avg_loudness_db >= -14
                         AND (tf.bpm >= 120 OR tf.bpm * 2 >= 150))',
        ],
    ];

    /** Stations only exist once some of the library has been analyzed. */
    public function available(): bool
    {
        $row = db()->fetch(
            "SELECT 1 FROM track_features WHERE error IS NULL LIMIT 1"
        );
        return $row !== null && $row !== false;
    }

    /** @return array<string,array{name:string,icon:string,blurb:string}> slug-keyed UI defs */
    public function stations(): array
    {
        return array_map(
            fn($s) => ['name' => $s['name'], 'icon' => $s['icon'], 'blurb' => $s['blurb']],
            self::STATIONS,
        );
    }

    /**
     * Deal a station queue for the session client, in the shared feed row
     * shape. Empty when the slug is unknown or no analyzed tracks match.
     *
     * @return array<int,array<string,mixed>>
     */
    public function build(string $slug): array
    {
        $station = self::STATIONS[$slug] ?? null;
        if ($station === null) {
            return [];
        }

        // Affinity: +3 for a like, plus per-play EXP decay over DECAY_DAYS
        // (skips count -2). NULL skipped (pre-tracking rows) counts as played.
        // Over-fetched 3x so the artist cap still fills a whole queue.
        $rows = db()->fetchAll(
            "SELECT " . MusicService::TRACK_COLUMNS . ", " . MusicService::LIKED_COLUMN . "
             FROM tracks t
             JOIN track_features tf ON tf.track_id = t.id AND tf.error IS NULL "
            . MusicService::TRACK_JOINS . "
             LEFT JOIN (
                 SELECT track_id,
                        SUM((CASE WHEN skipped = 1 THEN -2 ELSE 1 END)
                            * EXP(-DATEDIFF(NOW(), created_at) / ?)) AS play_score
                 FROM track_plays
                 WHERE client_id = ?
                 GROUP BY track_id
             ) ps ON ps.track_id = t.id
             LEFT JOIN track_likes tl2 ON tl2.track_id = t.id AND tl2.client_id = ?
             WHERE " . $station['where'] . "
             ORDER BY (IFNULL(ps.play_score, 0)
                       + IF(tl2.id IS NULL, 0, 3)
                       + RAND() * ?) DESC
             LIMIT " . (self::SIZE * 3),
            [client()->id, self::DECAY_DAYS, client()->id, client()->id, self::NOVELTY],
        );

        $queue  = [];
        $per    = [];
        foreach ($rows as $row) {
            $artist = $row['artist_hash'];
            if (($per[$artist] ?? 0) >= self::ARTIST_CAP) {
                continue;
            }
            $per[$artist] = ($per[$artist] ?? 0) + 1;
            $queue[] = $row;
            if (count($queue) >= self::SIZE) {
                break;
            }
        }

        // Queue and player expect the shared feed row shape ('hash', not
        // the raw 'track_hash' alias).
        return $this->music->mapTrackRows($queue);
    }
}
