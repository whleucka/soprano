<?php

namespace App\Services\Soprano;

/**
 * On-demand mood/activity stations built from audio features (track_features,
 * backfilled by TrackFeaturesService) crossed with per-client affinity:
 *
 *   station feature filter (BPM / danceability / loudness / key)
 *     × affinity (likes + recency-decayed plays, skips counted against)
 *     + a novelty knob (random jitter) so no two spins are identical
 *
 * Feature thresholds are percentile-anchored, not fixed: {d75} resolves to
 * the library's 75th-percentile danceability, {l25} to its 25th-percentile
 * loudness, and so on (see thresholds()). "Danceable" therefore means "more
 * danceable than most of this library", so stations keep their character as
 * the library grows instead of drifting off hand-picked cutoffs. BPM stays
 * absolute — dance tempo is a musical fact, not a relative one — and is
 * matched with its half/double octave because the extractor can octave-jump.
 *
 * Stations are ephemeral — they load straight into the play queue and are
 * never saved as playlists (unlike the nightly AutoPlaylistService mixes).
 * Pool sizes per station can be inspected with `bin/console soprano:stations`.
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

    /**
     * 'hours' is an optional [start, end) local-time window (may wrap
     * midnight) used to badge one station as the right-now pick on the home
     * rail — it never hides a station, only suggests. Untimed stations
     * (Rainy Day, Full Throttle) are weather/activity moods, not clock moods.
     */
    public const STATIONS = [
        'party' => [
            'name'  => 'Party',
            'icon'  => 'bi-cup-straw',
            'blurb' => 'Danceable and upbeat',
            'where' => '(tf.danceability >= {d75}
                         AND (tf.bpm BETWEEN 95 AND 170 OR tf.bpm / 2 BETWEEN 95 AND 170))',
            'hours' => [17, 21],
        ],
        'feel-good' => [
            'name'  => 'Feel Good',
            'icon'  => 'bi-sun',
            'blurb' => 'Bright, bouncy, major-key',
            'where' => "(tf.danceability >= {d60}
                         AND tf.key_scale = 'major'
                         AND (tf.bpm BETWEEN 100 AND 165 OR tf.bpm / 2 BETWEEN 100 AND 165)
                         AND tf.avg_loudness_db >= {l25})",
            'hours' => [6, 12],
        ],
        'chill' => [
            'name'  => 'Chill',
            'icon'  => 'bi-moon-stars',
            'blurb' => 'Quieter, low-key listening',
            'where' => '(tf.danceability < {d40} AND tf.avg_loudness_db <= {l40})',
            'hours' => [21, 23],
        ],
        'wind-down' => [
            'name'  => 'Wind Down',
            'icon'  => 'bi-cloud-moon',
            'blurb' => 'Soft and slow, lights off',
            'where' => '(tf.danceability < {d40}
                         AND tf.avg_loudness_db <= {l25}
                         AND tf.bpm < 115)',
            'hours' => [23, 6],
        ],
        'rainy-day' => [
            'name'  => 'Rainy Day',
            'icon'  => 'bi-cloud-drizzle',
            'blurb' => 'Minor-key and moody',
            'where' => "(tf.key_scale = 'minor'
                         AND tf.avg_loudness_db <= {l50}
                         AND tf.danceability < {d60})",
        ],
        'full-throttle' => [
            'name'  => 'Full Throttle',
            'icon'  => 'bi-lightning-charge',
            'blurb' => 'Loud and fast',
            'where' => '(tf.avg_loudness_db >= {l75}
                         AND tf.energy >= {e50}
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

    /**
     * Slug-keyed UI defs with the time-of-day pick flagged ('now') and moved
     * to the front of the rail.
     *
     * @return array<string,array{name:string,icon:string,blurb:string,now:bool}>
     */
    public function stations(): array
    {
        $suggested = self::suggestedFor((int) date('G'));

        $out = [];
        foreach (self::STATIONS as $slug => $s) {
            $out[$slug] = [
                'name'  => $s['name'],
                'icon'  => $s['icon'],
                'blurb' => $s['blurb'],
                'now'   => $slug === $suggested,
            ];
        }
        if ($suggested !== null) {
            $out = [$suggested => $out[$suggested]] + $out;
        }

        return $out;
    }

    /**
     * The station whose hours window covers the given local hour, or null
     * outside every window. Windows may wrap midnight ([23, 6) = 11pm–6am);
     * definition order breaks ties.
     */
    public static function suggestedFor(int $hour): ?string
    {
        foreach (self::STATIONS as $slug => $s) {
            $window = $s['hours'] ?? null;
            if ($window === null) {
                continue;
            }
            [$start, $end] = $window;
            $in = $start <= $end
                ? ($hour >= $start && $hour < $end)
                : ($hour >= $start || $hour < $end);
            if ($in) {
                return $slug;
            }
        }
        return null;
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
        $thresholds = $this->thresholds();
        if ($thresholds === null) {
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
             WHERE " . self::resolveWhere($station['where'], $thresholds) . "
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

    /**
     * Tuning report for `soprano:stations`: analysis coverage, the resolved
     * percentile thresholds, and each station's pool size. Runs without a
     * session (no client()), so the scheduler/CLI can use it.
     *
     * @return object{total:int,analyzed:int,errors:int,
     *               thresholds:?array<string,string>,
     *               stations:array<string,array{name:string,pool:int,where:string,hours:?array{0:int,1:int}}>}
     */
    public function report(): object
    {
        $counts = db()->fetch(
            "SELECT (SELECT COUNT(*) FROM tracks) AS total,
                    SUM(error IS NULL) AS analyzed,
                    SUM(error IS NOT NULL) AS errors
             FROM track_features",
        );
        $thresholds = $this->thresholds();

        $stations = [];
        foreach (self::STATIONS as $slug => $s) {
            $pool = 0;
            $where = '';
            if ($thresholds !== null) {
                $where = self::resolveWhere($s['where'], $thresholds);
                $row = db()->fetch(
                    "SELECT COUNT(*) AS pool FROM track_features tf
                     WHERE tf.error IS NULL AND $where",
                );
                $pool = (int) ($row['pool'] ?? 0);
            }
            $stations[$slug] = [
                'name'  => $s['name'],
                'pool'  => $pool,
                'where' => $where,
                'hours' => $s['hours'] ?? null,
            ];
        }

        return (object) [
            'total'      => (int) ($counts['total'] ?? 0),
            'analyzed'   => (int) ($counts['analyzed'] ?? 0),
            'errors'     => (int) ($counts['errors'] ?? 0),
            'thresholds' => $thresholds,
            'stations'   => $stations,
        ];
    }

    /**
     * Random sample from a station's pool for eyeballing threshold fit —
     * artist/title plus the features the station filters on. No session
     * needed (no liked column), so it works from the CLI.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sample(string $slug, int $limit = 10): array
    {
        $station = self::STATIONS[$slug] ?? null;
        $thresholds = $this->thresholds();
        if ($station === null || $thresholds === null) {
            return [];
        }

        return db()->fetchAll(
            "SELECT ar.name AS artist, tm.title AS title,
                    tf.bpm, tf.danceability, tf.energy,
                    tf.avg_loudness_db, tf.key_root, tf.key_scale
             FROM tracks t
             JOIN track_features tf ON tf.track_id = t.id AND tf.error IS NULL "
            . MusicService::TRACK_JOINS . "
             WHERE " . self::resolveWhere($station['where'], $thresholds) . "
             ORDER BY RAND()
             LIMIT " . max(1, $limit),
        );
    }

    /**
     * Replace {token}s in a station WHERE clause with resolved numeric
     * thresholds. Tokens map is '{d75}' => '1.2633' style, values already
     * formatted as SQL literals.
     */
    public static function resolveWhere(string $where, array $thresholds): string
    {
        return strtr($where, $thresholds);
    }

    /**
     * Library-relative feature thresholds, one window-function query:
     * d* = danceability percentiles, l* = avg_loudness_db, e* = energy.
     * Null before any track has been analyzed. Values are formatted as
     * locale-safe SQL numeric literals keyed by their {token}.
     *
     * @return ?array<string,string>
     */
    private function thresholds(): ?array
    {
        $row = db()->fetch(
            "SELECT DISTINCT
                PERCENTILE_CONT(0.40) WITHIN GROUP (ORDER BY danceability) OVER () AS d40,
                PERCENTILE_CONT(0.60) WITHIN GROUP (ORDER BY danceability) OVER () AS d60,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY danceability) OVER () AS d75,
                PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY avg_loudness_db) OVER () AS l25,
                PERCENTILE_CONT(0.40) WITHIN GROUP (ORDER BY avg_loudness_db) OVER () AS l40,
                PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY avg_loudness_db) OVER () AS l50,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY avg_loudness_db) OVER () AS l75,
                PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY energy) OVER () AS e50
             FROM track_features WHERE error IS NULL",
        );
        if (!$row) {
            return null;
        }

        $thresholds = [];
        foreach ($row as $key => $value) {
            $thresholds['{' . $key . '}'] = sprintf('%.4F', (float) $value);
        }
        return $thresholds;
    }
}
