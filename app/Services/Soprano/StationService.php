<?php

namespace App\Services\Soprano;

/**
 * On-demand mood/activity stations built from audio features (track_features,
 * backfilled by TrackFeaturesService) crossed with per-client affinity:
 *
 *   station feature filter (BPM / danceability / loudness / key)
 *     → weighted random sample, where affinity (likes + recency-decayed
 *       plays, skips counted against) sets a track's odds of being drawn
 *       rather than its rank, so no two spins are alike
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

    private const SIZE = 200;

    /** Max tracks per artist in one deal — a tenth of the queue, so house
     *  music doesn't monopolize Party (danceability concentrates heavily in
     *  a few artists). */
    private const ARTIST_CAP = 20;

    /**
     * Affinity is a sampling *weight*, not a sort key (see build()). BASE is
     * the weight of a never-heard track, so a like (+3) makes a track 4x more
     * likely to be drawn rather than guaranteeing it a slot. FLOOR keeps
     * heavily-skipped tracks (negative affinity) rare instead of impossible.
     */
    private const WEIGHT_BASE = 1.0;
    private const WEIGHT_FLOOR = 0.15;

    /** Half-life-ish horizon (days) for recency-decayed play scoring. */
    private const DECAY_DAYS = 90.0;

    /**
     * 'hours' is an optional [start, end) local-time window (may wrap
     * midnight) used to badge one station as the right-now pick on the home
     * rail — it never hides a station, only suggests. The timed windows tile
     * all 24 hours between them, so there is always a pick; the untimed
     * stations (Rainy Day, Full Throttle, Workout, Dynamics, Deep Cuts) are
     * weather/activity/mastering picks, not clock moods.
     *
     * Windows are evaluated against the *server* clock — date_default_timezone
     * from config('app.timezone'), set in Application — not the listener's, so
     * a client in another timezone gets this server's idea of evening.
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
            'where' => "(tf.danceability >= {d40} AND tf.danceability < {d75}
                         AND tf.key_scale = 'major'
                         AND (tf.bpm BETWEEN 100 AND 165 OR tf.bpm / 2 BETWEEN 100 AND 165)
                         AND tf.avg_loudness_db >= {l25})",
            'hours' => [6, 12],
        ],
        /**
         * Fills what used to be a five-hour hole in the badge windows
         * (12:00–17:00). The filter is a *steadiness* filter, not a mood one:
         * mid-loud, not danceable, a dyn_complexity ceiling so nothing lurches
         * in volume, and low zcr so nothing is abrasive. The loudness floor is
         * what separates it from Chill — Chill is defined by being quiet, this
         * by sitting mid-loud and never moving, so the pools are disjoint by
         * construction.
         *
         * It was called Focus first, which was wrong: on a rock-heavy library
         * "steady and unobtrusive" still deals Quiet Riot and Deftones, and
         * tightening zcr to the 30th percentile only shrank the pool (512 →
         * 206) without changing its character. Adding an energy ceiling on top
         * left 2 tracks. There is no background-music cluster to find on these
         * axes here, so the station is named for what it actually selects.
         */
        'steady' => [
            'name'  => 'Steady',
            'icon'  => 'bi-water',
            'blurb' => 'Even-keeled, nothing jarring',
            'where' => '(tf.avg_loudness_db > {l40}
                         AND tf.avg_loudness_db < {l75}
                         AND tf.danceability < {d60}
                         AND tf.dyn_complexity < {dc40}
                         AND tf.zcr < {z60})',
            'hours' => [12, 17],
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
        /**
         * Full Throttle and Workout split the same loud-and-fast space by
         * timbre, because "loud + fast + energetic" was one undifferentiated
         * 3.4k-track pool that a Workout station would have sat 82% inside.
         * zcr (zero-crossing rate) separates them: high = abrasive and
         * distorted (guitars, cymbals), low = smooth and tonal (synths,
         * bass-driven). Workout additionally wants a steady ride with no
         * quiet passages to break stride, hence the dyn_complexity ceiling.
         * The {z60} split keeps them disjoint by construction.
         */
        'full-throttle' => [
            'name'  => 'Full Throttle',
            'icon'  => 'bi-lightning-charge',
            'blurb' => 'Loud, fast and abrasive',
            'where' => '(tf.avg_loudness_db >= {l75}
                         AND tf.energy >= {e50}
                         AND (tf.bpm >= 120 OR tf.bpm * 2 >= 150)
                         AND tf.zcr >= {z60})',
        ],
        'workout' => [
            'name'  => 'Workout',
            'icon'  => 'bi-heart-pulse',
            'blurb' => 'Driving and relentless',
            'where' => '(tf.avg_loudness_db >= {l75}
                         AND tf.energy >= {e50}
                         AND (tf.bpm >= 120 OR tf.bpm * 2 >= 150)
                         AND tf.zcr < {z60}
                         AND tf.dyn_complexity < {dc40})',
        ],

        /**
         * The anti-loudness-war pool, and the only station that wants *high*
         * dyn_complexity (everywhere else it appears as a ceiling): records
         * that still have quiet passages to be quiet. The loudness ceiling
         * keeps brickwalled masters out and makes it disjoint from both loud
         * stations, which sit above {l75}.
         */
        'dynamics' => [
            'name'  => 'Dynamics',
            'icon'  => 'bi-soundwave',
            'blurb' => 'Wide range, room to breathe',
            'where' => '(tf.dyn_complexity >= {dc75}
                         AND tf.avg_loudness_db <= {l50})',
        ],

        /**
         * The one station that filters on listening history instead of audio
         * features: tracks never played on this client, by artists already
         * liked or played. 93% of the library has never been played, so a
         * plain "unheard" filter would just be shuffle — pinning it to
         * familiar artists is what makes it a recommendation. Orthogonal to
         * every feature station above, so it can't duplicate them.
         */
        'deep-cuts' => [
            'name'     => 'Deep Cuts',
            'icon'     => 'bi-compass',
            'blurb'    => "Never played, by artists you're into",
            'where'    => '1',
            'affinity' => self::UNHEARD_BY_FAMILIAR_ARTIST,
        ],
    ];

    /**
     * Extra build()-only predicate for 'deep-cuts'. References ps (the
     * per-client play-score subquery, NULL-joined when the track has never
     * been played) and takes two client-id params for the familiar-artist
     * lookup. Can't live in 'where': that clause has to stand alone against
     * track_features for report()/sample(), which run without a session.
     */
    private const UNHEARD_BY_FAMILIAR_ARTIST =
        "ps.track_id IS NULL
         AND t.track_artist_id IN (
             SELECT t2.track_artist_id
             FROM tracks t2
             LEFT JOIN track_plays p2 ON p2.track_id = t2.id AND p2.client_id = ?
             LEFT JOIN track_likes l2 ON l2.track_id = t2.id AND l2.client_id = ?
             WHERE p2.id IS NOT NULL OR l2.id IS NOT NULL
         )";

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
     * Stations whose pool is empty are dropped rather than rendered: the play
     * endpoint no-ops silently on an empty deal, so a button that cannot
     * possibly play anything is worse than no button. In a fully analyzed
     * library nothing is dropped — this only matters while the features
     * backfill is still working through the library.
     *
     * @return array<string,array{name:string,icon:string,blurb:string,now:bool}>
     */
    public function stations(): array
    {
        // One percentile scan plus one pool scan, both over the whole
        // features table, on a rail that every home page load renders and
        // re-renders on a timer. Pools only move as the backfill progresses,
        // so an hour of staleness costs nothing.
        // Keyed by the station definitions themselves, so editing a station
        // takes effect on the next render instead of leaving the rail culling
        // against an hour-stale pool map — a newly added slug is missing from
        // that map, and a missing slug reads as an empty pool and vanishes.
        $pools = cache()->remember(
            'soprano:station_pools:' . substr(md5(json_encode(self::STATIONS)), 0, 8),
            (int) config('soprano.station_pools_ttl'),
            fn() => $this->pools($this->thresholds()),
        );

        $out = [];
        foreach (self::STATIONS as $slug => $s) {
            if (($pools[$slug] ?? 0) < 1) {
                continue;
            }
            $out[$slug] = [
                'name'  => $s['name'],
                'icon'  => $s['icon'],
                'blurb' => $s['blurb'],
                'now'   => false,
            ];
        }

        $suggested = self::suggestedFor((int) date('G'), array_keys($out));
        if ($suggested !== null) {
            $out[$suggested]['now'] = true;
            $out = [$suggested => $out[$suggested]] + $out;
        }

        return $out;
    }

    /**
     * The station to badge as the right-now pick for the given local hour.
     *
     * The hours windows tile all 24 hours (asserted in StationServiceTest),
     * so the clock alone answers this in the normal case. $eligible narrows
     * the candidates to stations that actually have a pool; when the hour's
     * own station is not among them the fallback is the first eligible timed
     * station in definition order, and failing that the first eligible
     * station at all — the rail's lead button doubles as the badge, so
     * leaving it unset would mean leading with whatever happens to be first
     * in the array literal. Null only when nothing is eligible.
     *
     * Windows may wrap midnight ([23, 6) = 11pm–6am); definition order breaks
     * ties, though no two windows currently overlap.
     *
     * @param ?string[] $eligible Slugs to choose from; null means all.
     */
    public static function suggestedFor(int $hour, ?array $eligible = null): ?string
    {
        $ok = fn(string $slug): bool => $eligible === null || in_array($slug, $eligible, true);

        foreach (self::STATIONS as $slug => $s) {
            $window = $s['hours'] ?? null;
            if ($window === null || !$ok($slug)) {
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

        if ($eligible === null) {
            return null;
        }
        foreach (self::STATIONS as $slug => $s) {
            if (isset($s['hours']) && $ok($slug)) {
                return $slug;
            }
        }
        return $eligible[0] ?? null;
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
        //
        // Affinity picks the odds, not the tracks. Sorting by affinity made a
        // station deterministic: Feel Good had 42 tracks scoring above the
        // jitter for its slots, so every spin dealt the same favourites and the
        // ~2.3k never-heard tracks in a pool were unreachable. Instead each row
        // draws an Exp(weight) race key (-LOG(U)/w, smallest wins), which
        // samples without replacement proportional to weight — a favourite is
        // merely likelier, and the long tail is always in play.
        //
        // 1 - RAND() lands in (0,1] so LOG() never sees 0.
        //
        // Over-fetched 3x so the artist cap still fills a whole queue.
        //
        // 'affinity' stations (Deep Cuts) add a listening-history predicate on
        // top of the feature filter; its params slot in right after the WHERE.
        $where  = self::resolveWhere($station['where'], $thresholds);
        $params = [client()->id, self::DECAY_DAYS, client()->id, client()->id];
        if (isset($station['affinity'])) {
            $where   .= ' AND ' . $station['affinity'];
            $params[] = client()->id;
            $params[] = client()->id;
        }
        // Silence and interludes analyze fine and can score well on a feature
        // filter (a silent track is very "chill"), so the gate goes here, not
        // in the station definitions.
        $where .= ' AND ' . MusicService::LONG_ENOUGH;
        $params[] = self::WEIGHT_FLOOR;
        $params[] = self::WEIGHT_BASE;

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
             WHERE $where
             ORDER BY -LOG(1 - RAND())
                      / GREATEST(?, IFNULL(ps.play_score, 0)
                                    + IF(tl2.id IS NULL, 0, 3)
                                    + ?) ASC
             LIMIT " . (self::SIZE * 3),
            $params,
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
     *               stations:array<string,array{name:string,pool:int,where:string,
     *                                           hours:?array{0:int,1:int},affinity:bool}>}
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
        // Deliberately uncached: `soprano:stations` is the tuning tool, so it
        // has to see the library as it is right now, not as the home rail
        // last cached it.
        $pools = $this->pools($thresholds);

        $stations = [];
        foreach (self::STATIONS as $slug => $s) {
            $stations[$slug] = [
                'name'  => $s['name'],
                'pool'  => $pools[$slug] ?? 0,
                'where' => $thresholds === null
                    ? ''
                    : self::resolveWhere($s['where'], $thresholds),
                'hours' => $s['hours'] ?? null,
                // Pool is client-independent only for feature stations. An
                // affinity station's real pool depends on listening history,
                // which report() can't see (it runs without a session), so
                // its count is an upper bound the caller should flag.
                'affinity' => isset($s['affinity']),
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
               AND " . MusicService::LONG_ENOUGH . "
             ORDER BY RAND()
             LIMIT " . max(1, $limit),
        );
    }

    /**
     * Pool size per station slug — the number of tracks build() would
     * actually have to deal from, length gate included, so a tuning report
     * never overstates a pool.
     *
     * One SUM() per station over a single scan rather than a COUNT per
     * station: with a station list this long that is the difference between
     * one pass over track_features and a dozen, and stations() runs it on a
     * page fragment. FALSE sums as 0 and NULL (an unanalyzed feature column)
     * is skipped, so each SUM is exactly the count of matching rows.
     *
     * Affinity stations (Deep Cuts) are counted on their feature filter only;
     * their listening-history predicate is per-client and lives in build(),
     * so the number here is an upper bound. That is deliberate — it keeps the
     * station out of the empty-pool cull in stations(), where dropping it for
     * a client with no history would be right but is not worth a per-client
     * query on every home load.
     *
     * All zeros before any track has been analyzed.
     *
     * @param ?array<string,string> $thresholds
     * @return array<string,int>
     */
    private function pools(?array $thresholds): array
    {
        $empty = array_fill_keys(array_keys(self::STATIONS), 0);
        if ($thresholds === null) {
            return $empty;
        }

        $selects = [];
        foreach (self::STATIONS as $slug => $s) {
            $selects[] = 'SUM(' . self::resolveWhere($s['where'], $thresholds) . ') AS `' . $slug . '`';
        }

        $row = db()->fetch(
            "SELECT " . implode(",\n                    ", $selects) . "
             FROM track_features tf
             JOIN tracks t ON t.id = tf.track_id
             WHERE tf.error IS NULL
               AND " . MusicService::LONG_ENOUGH,
        );
        if (!$row) {
            return $empty;
        }

        $pools = [];
        foreach ($empty as $slug => $zero) {
            $pools[$slug] = (int) ($row[$slug] ?? $zero);
        }
        return $pools;
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
     * d* = danceability percentiles, l* = avg_loudness_db, e* = energy,
     * z* = zcr (timbre), dc* = dyn_complexity.
     * (key_strength has no percentile here on purpose: an Acoustic station
     * built on it selected "confidently keyed", which most rock is, so the
     * feature is analyzed but unused.)
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
                PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY energy) OVER () AS e50,
                PERCENTILE_CONT(0.60) WITHIN GROUP (ORDER BY zcr) OVER () AS z60,
                PERCENTILE_CONT(0.40) WITHIN GROUP (ORDER BY dyn_complexity) OVER () AS dc40,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY dyn_complexity) OVER () AS dc75
             FROM track_features WHERE error IS NULL",
        );
        if (!$row) {
            return null;
        }

        $thresholds = [];
        foreach ($row as $key => $value) {
            // 6dp, not 4: zcr values cluster in a ~0.03 band, so 4dp would
            // round distinct tracks onto the split point.
            $thresholds['{' . $key . '}'] = sprintf('%.6F', (float) $value);
        }
        return $thresholds;
    }
}
