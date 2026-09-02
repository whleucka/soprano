<?php

namespace App\Services\Admin;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Client;
use App\Models\Track;
use App\Services\Soprano\MusicService;

/**
 * What the library is actually doing, as opposed to what the web server is.
 *
 * `track_plays` has carried `ms_played`, `skipped` and `source` since the
 * feedback migration, and nothing read them — the dashboard was measuring HTTP
 * requests, which tells you the app is up but nothing about whether anyone
 * enjoyed it. Everything here is built on those three columns.
 *
 * Two counts, and they are NOT interchangeable:
 *
 *   PLAY       MusicService::CLOSED_PLAY — the app's own definition of a play,
 *              used by its Top Played feeds. It deliberately excludes skips,
 *              so a track you bailed on doesn't climb the charts. Use it for
 *              anything that ranks or totals listening.
 *   FINALIZED  Any play whose outcome was reported, skip or not. This is the
 *              only correct denominator for a completion or skip rate —
 *              computing one over PLAY rows returns 100% every time, because
 *              PLAY already threw the skips away.
 *
 * Both come out of a single pass per query via conditional aggregates rather
 * than a WHERE, so the two denominators never drift apart.
 *
 * `ms_played` is NULL on rows written before the column existed and on any
 * play whose tab died before reporting. Those COALESCE to zero, so listening
 * time is a floor, never an estimate.
 */
class PlaybackAnalyticsService
{
    /** Boolean expression — true when a row counts as a play. */
    private const PLAY = MusicService::CLOSED_PLAY;

    /** Boolean expression — true when a row's outcome is known. */
    private const FINALIZED = "tp.skipped IS NOT NULL";

    /**
     * The aggregate block every playback query selects. Kept in one place
     * because the alternative is six near-identical CASE expressions drifting
     * out of sync across the file.
     */
    private const AGGREGATES = "
        SUM(" . self::PLAY . ") AS plays,
        COALESCE(SUM(CASE WHEN " . self::PLAY . " THEN tp.ms_played END), 0) AS ms,
        SUM(" . self::FINALIZED . ") AS finalized,
        SUM(tp.skipped = 0) AS completed,
        SUM(tp.skipped = 1) AS skipped";

    /** Request-level cache — the KPI strip and the trend chart share a query. */
    private array $cache = [];

    private function cached(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $callback();
        }
        return $this->cache[$key];
    }

    /** Timestamp of the first play on record, used to bound the `all` range. */
    public function earliestPlay(): ?string
    {
        return $this->cached('earliest_play', function () {
            $row = db()->fetch("SELECT MIN(created_at) AS at FROM track_plays");
            return $row['at'] ?? null;
        });
    }

    /** Timestamp of the oldest library row, for the growth chart's axis. */
    private function earliestTrack(): ?string
    {
        return $this->cached('earliest_track', function () {
            $row = db()->fetch("SELECT MIN(created_at) AS at FROM tracks");
            return $row['at'] ?? null;
        });
    }

    /**
     * Normalise one aggregate row into the shape the rest of the class uses.
     * Completion is null rather than 0 when nothing was finalized, so a quiet
     * window renders as "—" instead of a damning 0%.
     */
    private function shape(array $row): array
    {
        $finalized = (int) ($row['finalized'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'plays' => (int) ($row['plays'] ?? 0),
            'ms' => (int) ($row['ms'] ?? 0),
            'finalized' => $finalized,
            'completed' => $completed,
            'skipped' => (int) ($row['skipped'] ?? 0),
            'completion' => $finalized > 0 ? $completed / $finalized * 100 : null,
        ];
    }

    // =========================================================================
    // Core series — one query, reused by the trend chart and every sparkline
    // =========================================================================

    /**
     * Per-bucket playback totals across the range, gap-filled so a quiet day
     * is a zero on the axis rather than a missing point the line jumps over.
     */
    public function getSeries(AnalyticsRange $range): array
    {
        return $this->cached("series_{$range->key}", function () use ($range) {
            [$where, $params] = $range->clause();
            [$sqlBucket, $phpFormat] = $range->bucketExpression('tp.created_at');

            $rows = db()->fetchAll(
                "SELECT $sqlBucket AS bucket," . self::AGGREGATES . "
                 FROM track_plays tp
                 WHERE $where
                 GROUP BY bucket
                 ORDER BY bucket",
                $params
            );

            $byBucket = [];
            foreach ($rows as $row) {
                $byBucket[(string) $row['bucket']] = $this->shape($row);
            }

            return $this->fillBuckets(
                $range,
                $this->earliestPlay(),
                $phpFormat,
                fn(?array $row) => [
                    'plays' => $row['plays'] ?? 0,
                    'ms' => $row['ms'] ?? 0,
                    'finalized' => $row['finalized'] ?? 0,
                    'completed' => $row['completed'] ?? 0,
                    'skipped' => $row['skipped'] ?? 0,
                ],
                $byBucket
            );
        });
    }

    /**
     * Walk every bucket in the range and hand each one to $map, whether the
     * query returned a row for it or not. The 400-iteration ceiling is a
     * backstop: a mismatched SQL/PHP bucket format would otherwise never
     * produce a matching key and spin here forever.
     */
    private function fillBuckets(
        AnalyticsRange $range,
        ?string $earliest,
        string $phpFormat,
        callable $map,
        array $byBucket,
    ): array {
        $out = [];
        $cursor = $range->firstBucket($earliest);
        $end = new \DateTimeImmutable($range->until);
        $step = $range->bucketStep();

        for ($i = 0; $i < 400 && $cursor <= $end; $i++) {
            $out[] = [
                'label' => $range->bucketLabel($cursor),
                ...$map($byBucket[$cursor->format($phpFormat)] ?? null),
            ];
            $cursor = $cursor->modify($step);
        }

        return $out;
    }

    /**
     * Window totals. Takes a prebuilt clause so the same SQL serves both the
     * current and the previous window.
     */
    private function totals(string $where, array $params): array
    {
        $row = db()->fetch(
            "SELECT" . self::AGGREGATES . ",
                COUNT(DISTINCT CASE WHEN " . self::PLAY . " THEN tp.client_id END) AS listeners,
                COUNT(DISTINCT CASE WHEN " . self::PLAY . " THEN tp.track_id END) AS tracks
             FROM track_plays tp
             WHERE $where",
            $params
        ) ?: [];

        return $this->shape($row) + [
            'listeners' => (int) ($row['listeners'] ?? 0),
            'tracks' => (int) ($row['tracks'] ?? 0),
        ];
    }

    // =========================================================================
    // KPI strip
    // =========================================================================

    /**
     * The six headline numbers, each with a delta against the previous equal
     * window and a sparkline drawn from getSeries().
     */
    public function getKpis(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause();
        [$prevWhere, $prevParams] = $range->previousClause();

        $now = $this->totals($where, $params);
        $prev = $range->hasPrevious() ? $this->totals($prevWhere, $prevParams) : null;
        $series = $this->getSeries($range);

        // Per-bucket completion for the sparkline. Buckets with nothing
        // finalized hold at zero rather than being dropped, which keeps the
        // bars aligned with the other tiles' sparklines.
        $completionSpark = array_map(
            fn(array $b) => $b['finalized'] > 0
                ? (int) round($b['completed'] / $b['finalized'] * 100)
                : 0,
            $series
        );

        $growth = $this->getLibraryGrowth($range);

        return [
            [
                'label' => 'Listening time',
                'value' => format_duration($now['ms']),
                'icon' => 'headphones',
                'tone' => 'accent',
                'spark' => array_column($series, 'ms'),
                'delta' => $this->delta($now['ms'], $prev['ms'] ?? null),
                'note' => number_format($now['tracks']) . ' distinct tracks',
            ],
            [
                'label' => 'Plays',
                'value' => number_format($now['plays']),
                'icon' => 'play-circle',
                'tone' => 'info',
                'spark' => array_column($series, 'plays'),
                'delta' => $this->delta($now['plays'], $prev['plays'] ?? null),
                'note' => $this->perDay($now['plays'], $range) . ' a day',
            ],
            [
                'label' => 'Completion',
                'value' => $now['completion'] === null ? '—' : round($now['completion']) . '%',
                'icon' => 'check2-circle',
                'tone' => $now['completion'] === null
                    ? 'muted'
                    : $this->completionTone($now['completion']),
                'spark' => $completionSpark,
                'delta' => $this->delta($now['completion'], $prev['completion'] ?? null, points: true),
                'note' => number_format($now['skipped']) . ' skipped',
            ],
            [
                'label' => 'Listeners',
                'value' => number_format($now['listeners']),
                'icon' => 'people',
                // Not `success`: that tone means "above threshold" on this
                // dashboard, and a listener count has no threshold to be above.
                'tone' => 'primary',
                'spark' => array_column($series, 'finalized'),
                'delta' => $this->delta($now['listeners'], $prev['listeners'] ?? null),
                'note' => number_format((int) Client::countAll()) . ' registered',
            ],
            [
                'label' => 'Library',
                'value' => number_format((int) Track::countAll()),
                'icon' => 'music-note-beamed',
                'tone' => 'muted',
                'spark' => array_column($growth, 'added'),
                'delta' => null,
                'note' => format_duration($this->libraryLengthMs()) . ' of audio',
            ],
            [
                'label' => 'Reach',
                'value' => $this->libraryReach($now['tracks']) . '%',
                'icon' => 'pie-chart',
                'tone' => 'warning',
                // No sparkline: reach is distinct tracks against the whole
                // library, and the series carries no per-bucket distinct count.
                // A plays sparkline here would look like reach over time and
                // wouldn't be.
                'spark' => null,
                'delta' => null,
                'note' => 'of the library played',
            ],
        ];
    }

    /**
     * Percentage change, or null when there's nothing to compare against.
     * `points` renders the change as percentage *points* — a completion rate
     * moving 80% → 84% is "+4pt", not "+5%", which is true but unreadable.
     */
    private function delta(int|float|null $now, int|float|null $prev, bool $points = false): ?array
    {
        if ($now === null || $prev === null) {
            return null;
        }

        if ($points) {
            $change = $now - $prev;
            $text = sprintf('%+.0fpt', $change);
        } elseif ((float) $prev === 0.0) {
            // Growth from nothing is not a percentage anyone can read.
            return $now > 0 ? ['text' => 'new', 'direction' => 'up'] : null;
        } else {
            $change = ($now - $prev) / $prev * 100;
            $text = sprintf('%+.0f%%', $change);
        }

        return [
            'text' => $text,
            'direction' => abs($change) < 0.5 ? 'flat' : ($change > 0 ? 'up' : 'down'),
        ];
    }

    private function completionTone(float $percent): string
    {
        return $percent >= 80 ? 'success' : ($percent >= 60 ? 'warning' : 'danger');
    }

    /** Plays per day over the window, to one decimal while it's still small. */
    private function perDay(int $total, AnalyticsRange $range): string
    {
        $since = $range->since ?? $this->earliestPlay();
        if (!$since || $total === 0) {
            return '0';
        }

        $days = max(1, (int) (new \DateTimeImmutable($range->until))
            ->diff(new \DateTimeImmutable($since))->days);
        $rate = $total / $days;

        return number_format($rate, $rate < 10 ? 1 : 0);
    }

    /** Total playable duration of the library. */
    private function libraryLengthMs(): int
    {
        return $this->cached('library_ms', function () {
            $row = db()->fetch("SELECT COALESCE(SUM(length_ms), 0) AS ms FROM track_meta");
            return (int) ($row['ms'] ?? 0);
        });
    }

    /** Share of the library that saw at least one play in the window. */
    private function libraryReach(int $playedTracks): string
    {
        $total = (int) Track::countAll();
        return $total === 0 ? '0' : (string) round($playedTracks / $total * 100, 1);
    }

    // =========================================================================
    // Library growth
    // =========================================================================

    /**
     * Library growth over the range. `added` is per bucket, `total` is the
     * running library size, which is the line people actually want to see.
     */
    public function getLibraryGrowth(AnalyticsRange $range): array
    {
        return $this->cached("growth_{$range->key}", function () use ($range) {
            [$where, $params] = $range->clause('t.created_at');
            [$sqlBucket, $phpFormat] = $range->bucketExpression('t.created_at');

            $rows = db()->fetchAll(
                "SELECT $sqlBucket AS bucket, COUNT(*) AS added
                 FROM tracks t
                 WHERE $where
                 GROUP BY bucket
                 ORDER BY bucket",
                $params
            );

            $byBucket = [];
            foreach ($rows as $row) {
                $byBucket[(string) $row['bucket']] = ['added' => (int) $row['added']];
            }

            // Baseline: everything already in the library before the window,
            // so the running total is the real library size rather than a
            // count restarting from zero each time the range changes.
            $running = max(0, (int) Track::countAll() - array_sum(array_column($byBucket, 'added')));

            return $this->fillBuckets(
                $range,
                $this->earliestTrack(),
                $phpFormat,
                function (?array $row) use (&$running) {
                    $added = $row['added'] ?? 0;
                    $running += $added;
                    return ['added' => $added, 'total' => $running];
                },
                $byBucket
            );
        });
    }

    // =========================================================================
    // Source attribution — the point of the whole exercise
    // =========================================================================

    /**
     * Which part of the app playback came from.
     *
     * `source` is written as a family, optionally with a target after a colon:
     * `album`, `search`, `liked`, `random`, `artist`, `station:feel-good`,
     * `playlist:<hash>`, `artist-radio:<hash>`. Individual playlist and radio
     * hashes are meaningless in aggregate, so everything collapses to the
     * family here; getSourceQuality() breaks them back out, because there the
     * individual station is the whole question.
     */
    public function getPlaysBySource(AnalyticsRange $range): array
    {
        return $this->cached("sources_{$range->key}", function () use ($range) {
            [$where, $params] = $range->clause();

            $rows = db()->fetchAll(
                "SELECT
                    SUBSTRING_INDEX(COALESCE(tp.source, 'unknown'), ':', 1) AS family," . self::AGGREGATES . "
                 FROM track_plays tp
                 WHERE $where
                 GROUP BY family
                 HAVING plays > 0
                 ORDER BY plays DESC",
                $params
            );

            $total = array_sum(array_map(fn($r) => (int) $r['plays'], $rows));

            return array_map(function (array $row) use ($total) {
                $shaped = $this->shape($row);
                return [
                    'family' => $row['family'],
                    'label' => $this->sourceLabel($row['family']),
                    'icon' => $this->sourceIcon($row['family']),
                    'plays' => $shaped['plays'],
                    'ms' => $shaped['ms'],
                    'duration' => format_duration($shaped['ms']),
                    'share' => $total > 0 ? round($shaped['plays'] / $total * 100, 1) : 0.0,
                    'completion' => $shaped['completion'] === null
                        ? null
                        : (int) round($shaped['completion']),
                ];
            }, $rows);
        });
    }

    /**
     * Per-station and per-generated-feed quality: how often a track dealt by
     * that source got listened through instead of skipped.
     *
     * This is the scoreboard for everything Soprano generates. A station with
     * a 40% completion rate is picking the wrong tracks, and until now there
     * was no way to see that short of writing the SQL by hand.
     *
     * Sources with only a handful of finalized plays are excluded: a 0% rate
     * off two plays is noise, and it would sort straight to the top.
     */
    public function getSourceQuality(AnalyticsRange $range, int $minPlays = 10): array
    {
        return $this->cached("quality_{$range->key}_{$minPlays}", function () use ($range, $minPlays) {
            [$where, $params] = $range->clause();

            $rows = db()->fetchAll(
                "SELECT
                    COALESCE(tp.source, 'unknown') AS source," . self::AGGREGATES . ",
                    ROUND(SUM(tp.skipped = 0) / NULLIF(SUM(" . self::FINALIZED . "), 0) * 100) AS completion
                 FROM track_plays tp
                 WHERE $where
                 GROUP BY source
                 HAVING finalized >= ?
                 ORDER BY completion DESC, plays DESC",
                [...$params, $minPlays]
            );

            return array_map(function (array $row) {
                $completion = (int) $row['completion'];
                [$family, $target] = array_pad(explode(':', $row['source'], 2), 2, null);

                return [
                    'source' => $row['source'],
                    'family' => $family,
                    'label' => $this->sourceLabel($family, $target),
                    'icon' => $this->sourceIcon($family),
                    'plays' => (int) $row['plays'],
                    'finalized' => (int) $row['finalized'],
                    'skipped' => (int) $row['skipped'],
                    'duration' => format_duration((int) $row['ms']),
                    'completion' => $completion,
                    'tone' => $this->completionTone($completion),
                ];
            }, $rows);
        });
    }

    /**
     * Station slugs are human-readable; playlist and artist-radio targets are
     * 32-char hashes, so resolve the ones we can — the quality table has to
     * read as names, not hex.
     */
    private function sourceLabel(string $family, ?string $target = null): string
    {
        $base = match ($family) {
            'album' => 'Album',
            'artist' => 'Artist page',
            'artist-radio' => 'Artist radio',
            'search' => 'Search',
            'liked' => 'Liked tracks',
            'random' => 'Shuffle all',
            'station' => 'Station',
            'playlist' => 'Playlist',
            'podcast' => 'Podcast',
            'radio' => 'Radio',
            'unknown' => 'Unattributed',
            default => ucfirst(str_replace('-', ' ', $family)),
        };

        if ($target === null) {
            return $base;
        }

        $name = match ($family) {
            'station' => ucwords(str_replace('-', ' ', $target)),
            'playlist' => $this->playlistName($target),
            'artist-radio' => $this->artistName($target),
            default => null,
        };

        return $name ? "$base · $name" : $base;
    }

    private function playlistName(string $hash): ?string
    {
        $names = $this->cached('playlist_names', function () {
            $out = [];
            foreach (db()->fetchAll("SELECT hash, name FROM playlists") as $row) {
                $out[$row['hash']] = $row['name'];
            }
            return $out;
        });
        return $names[$hash] ?? null;
    }

    private function artistName(string $hash): ?string
    {
        $names = $this->cached('artist_names_by_hash', function () {
            $out = [];
            foreach (db()->fetchAll("SELECT hash, name FROM artists") as $row) {
                $out[$row['hash']] = $row['name'];
            }
            return $out;
        });
        return $names[$hash] ?? null;
    }

    private function sourceIcon(string $family): string
    {
        return match ($family) {
            'album' => 'disc',
            'artist' => 'person-circle',
            'artist-radio' => 'broadcast-pin',
            'search' => 'search',
            'liked' => 'heart-fill',
            'random' => 'shuffle',
            'station' => 'broadcast',
            'playlist' => 'music-note-list',
            'podcast' => 'mic',
            'radio' => 'radio',
            default => 'question-circle',
        };
    }

    // =========================================================================
    // Listening clock
    // =========================================================================

    /**
     * Plays by day-of-week × hour. The old dashboard had a heatmap of the same
     * shape built from HTTP requests, which lit up whenever a phone woke up
     * and polled — this one only lights up when something was played.
     */
    public function getListeningClock(AnalyticsRange $range): array
    {
        return $this->cached("clock_{$range->key}", function () use ($range) {
            [$where, $params] = $range->clause();

            $rows = db()->fetchAll(
                "SELECT
                    DAYOFWEEK(tp.created_at) AS dow,
                    HOUR(tp.created_at) AS hour,
                    SUM(" . self::PLAY . ") AS plays
                 FROM track_plays tp
                 WHERE $where
                 GROUP BY dow, hour",
                $params
            );

            // DAYOFWEEK is 1=Sunday. Re-index to 0=Monday so the grid reads
            // Mon–Sun like the rest of the app's week logic.
            $matrix = array_fill(0, 7, array_fill(0, 24, 0));
            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $max = 0;
            $peak = null;
            $total = 0;

            foreach ($rows as $row) {
                $day = ((int) $row['dow'] + 5) % 7;
                $hour = (int) $row['hour'];
                $plays = (int) $row['plays'];
                $matrix[$day][$hour] = $plays;
                $total += $plays;
                if ($plays > $max) {
                    $max = $plays;
                    $peak = sprintf('%s %02d:00', $days[$day], $hour);
                }
            }

            return [
                'matrix' => $matrix,
                'max' => $max,
                'total' => $total,
                'days' => $days,
                'peak' => $peak,
            ];
        });
    }

    // =========================================================================
    // Leaderboards
    // =========================================================================

    /**
     * Most-played tracks in the window, with cover art and the completion rate
     * that says whether those plays were actually listens.
     */
    public function getTopTracks(AnalyticsRange $range, int $limit = 8): array
    {
        [$where, $params] = $range->clause();

        $rows = db()->fetchAll(
            "SELECT
                tm.title AS title,
                ar.name AS artist,
                al.cover AS cover,
                al.dominant_color AS dominant_color," . self::AGGREGATES . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.track_artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE $where
             GROUP BY t.id
             HAVING plays > 0
             ORDER BY plays DESC, tm.title ASC
             LIMIT " . $this->limit($limit),
            $params
        );

        return array_map(fn(array $row) => $this->leaderboardRow(
            $row,
            $row['title'] ?: 'Unknown',
            $row['artist'] ?: 'Unknown artist',
        ), $rows);
    }

    public function getTopArtists(AnalyticsRange $range, int $limit = 8): array
    {
        [$where, $params] = $range->clause();

        $rows = db()->fetchAll(
            "SELECT
                ar.name AS artist,
                ar.image AS cover,
                COUNT(DISTINCT CASE WHEN " . self::PLAY . " THEN t.id END) AS tracks," . self::AGGREGATES . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE $where
             GROUP BY ar.id
             HAVING plays > 0
             ORDER BY plays DESC, ar.name ASC
             LIMIT " . $this->limit($limit),
            $params
        );

        return array_map(fn(array $row) => $this->leaderboardRow(
            $row,
            $row['artist'] ?: 'Unknown artist',
            (int) $row['tracks'] . ' track' . ($row['tracks'] == 1 ? '' : 's'),
        ), $rows);
    }

    public function getTopAlbums(AnalyticsRange $range, int $limit = 8): array
    {
        [$where, $params] = $range->clause();

        $rows = db()->fetchAll(
            "SELECT
                al.title AS album,
                al.cover AS cover,
                al.dominant_color AS dominant_color,
                ar.name AS artist," . self::AGGREGATES . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = al.artist_id
             WHERE $where
             GROUP BY al.id
             HAVING plays > 0
             ORDER BY plays DESC, al.title ASC
             LIMIT " . $this->limit($limit),
            $params
        );

        return array_map(fn(array $row) => $this->leaderboardRow(
            $row,
            $row['album'] ?: 'Unknown album',
            $row['artist'] ?: 'Unknown artist',
        ), $rows);
    }

    /**
     * LIMIT is interpolated, not bound — the driver quotes a bound integer as
     * a string, which MariaDB rejects in a LIMIT clause. Clamped to a literal
     * int so there's nothing to inject.
     */
    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    /**
     * Shared shape for the three leaderboards so one template renders all of
     * them. `cover` falls back to the app's placeholder rather than an empty
     * src, which browsers render as a broken-image glyph.
     */
    private function leaderboardRow(array $row, string $title, string $subtitle): array
    {
        $shaped = $this->shape($row);
        $completion = $shaped['completion'] === null ? null : (int) round($shaped['completion']);

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'cover' => ($row['cover'] ?? null) ?: '/images/no-album-art.png',
            'accent' => $row['dominant_color'] ?? null,
            'plays' => $shaped['plays'],
            'duration' => format_duration($shaped['ms']),
            'completion' => $completion,
            'tone' => $completion === null ? 'muted' : $this->completionTone($completion),
        ];
    }

    // =========================================================================
    // Listeners
    // =========================================================================

    /**
     * Per-client listening. Soprano has a handful of clients, not a user base,
     * so this is a table rather than a chart — at that size the individual
     * rows are the interesting part.
     */
    public function getListeners(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause();

        $rows = db()->fetchAll(
            "SELECT
                c.id AS id,
                c.username AS username," . self::AGGREGATES . ",
                MAX(tp.created_at) AS last_play
             FROM track_plays tp
             JOIN clients c ON c.id = tp.client_id
             WHERE $where
             GROUP BY c.id
             HAVING plays > 0
             ORDER BY ms DESC",
            $params
        );

        $topArtists = $this->topArtistPerClient($range);
        $busiest = max(1, ...array_map(fn($r) => (int) $r['ms'], $rows ?: [['ms' => 1]]));

        return array_map(function (array $row) use ($topArtists, $busiest) {
            $shaped = $this->shape($row);
            $completion = $shaped['completion'] === null
                ? null
                : (int) round($shaped['completion']);

            return [
                'username' => $row['username'],
                'plays' => $shaped['plays'],
                'duration' => format_duration($shaped['ms']),
                // Bar width relative to the busiest listener, so the table
                // reads as a ranking without needing a chart.
                'share' => (int) round($shaped['ms'] / $busiest * 100),
                'completion' => $completion,
                'tone' => $completion === null ? 'muted' : $this->completionTone($completion),
                'top_artist' => $topArtists[(int) $row['id']] ?? null,
                'ago' => $this->timeAgo($row['last_play']),
            ];
        }, $rows);
    }

    /**
     * Each client's most-played artist in the window, resolved in one pass
     * rather than a query per listener.
     */
    private function topArtistPerClient(AnalyticsRange $range): array
    {
        [$where, $params] = $range->clause();

        $rows = db()->fetchAll(
            "SELECT tp.client_id AS client_id, ar.name AS artist, SUM(" . self::PLAY . ") AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE $where
             GROUP BY tp.client_id, ar.id
             HAVING plays > 0
             ORDER BY plays DESC",
            $params
        );

        // Ordered by plays desc, so the first row seen per client wins.
        $top = [];
        foreach ($rows as $row) {
            $top[(int) $row['client_id']] ??= $row['artist'];
        }
        return $top;
    }

    // =========================================================================
    // Library totals (range-independent)
    // =========================================================================

    public function getLibraryTotals(): array
    {
        return $this->cached('library_totals', fn() => [
            'tracks' => (int) Track::countAll(),
            'albums' => (int) Album::countAll(),
            'artists' => (int) Artist::countAll(),
            'duration' => format_duration($this->libraryLengthMs()),
        ]);
    }

    private function timeAgo(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $diff = time() - strtotime($datetime);
        if ($diff < 60) {
            return 'just now';
        }
        $intervals = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
        ];
        foreach ($intervals as $seconds => $label) {
            $count = (int) floor($diff / $seconds);
            if ($count >= 1) {
                return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }
}
