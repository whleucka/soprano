<?php

namespace App\Services\Soprano;

/**
 * "Year in Review" listening stats for the current client, computed from
 * track_plays. The page itself lives at /wrapped/{year} and works year-round
 * (handy for testing); the home banner only appears during the season window
 * (Dec 15 – Jan 15), pointing at the year that just wrapped.
 */
class WrappedService
{
    public function __construct(private MusicService $music) {}

    private const MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /** How many top tracks "Play your top tracks" queues up. */
    public const MIX_SIZE = 50;

    /**
     * Listening "personality" by time of day. Hours are local wall-clock
     * hours (0–23); night wraps past midnight. Every hour belongs to exactly
     * one part — WrappedServiceTest holds that invariant.
     */
    public const DAYPARTS = [
        'morning' => [
            'label' => 'Early Bird',
            'icon'  => 'bi-sunrise',
            'blurb' => 'Mornings are when you press play',
            'hours' => [5, 6, 7, 8, 9, 10],
        ],
        'afternoon' => [
            'label' => 'Daytime Listener',
            'icon'  => 'bi-sun',
            'blurb' => 'Your music keeps the workday moving',
            'hours' => [11, 12, 13, 14, 15, 16],
        ],
        'evening' => [
            'label' => 'Evening Unwinder',
            'icon'  => 'bi-sunset',
            'blurb' => 'You save the good stuff for after hours',
            'hours' => [17, 18, 19, 20, 21],
        ],
        'night' => [
            'label' => 'Night Owl',
            'icon'  => 'bi-moon-stars',
            'blurb' => 'The best listening happens after dark',
            'hours' => [22, 23, 0, 1, 2, 3, 4],
        ],
    ];

    /**
     * The year a review should cover right now: the current year through
     * December, last year in January (the season straddles New Year's).
     */
    public function seasonYear(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $year = (int) $now->format('Y');

        return (int) $now->format('n') === 1 ? $year - 1 : $year;
    }

    /** True from Dec 15 through Jan 15 — when the home banner shows. */
    public function isSeason(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $day   = (int) $now->format('j');

        return ($month === 12 && $day >= 15) || ($month === 1 && $day <= 15);
    }

    /**
     * Full review payload for one calendar year. `plays` of 0 means there is
     * nothing to show and the view renders its empty state.
     *
     * @return array<string,mixed>
     */
    public function review(int $year): array
    {
        [$from, $to] = self::bounds($year);

        // Play history only goes back so far (tracking began mid-2026). When
        // it starts inside this year, say so, and start the month chart there
        // instead of leading with a row of empty bars that read as "you
        // didn't listen in spring".
        $since   = $this->trackedSince($year);
        $byMonth = $this->playsByMonth($from, $to);
        if ($since !== null) {
            $byMonth = array_slice($byMonth, $since['month'] - 1);
        }
        $byHour = $this->playsByHour($from, $to);

        return [
            'year'         => $year,
            'since'        => $since,
            'totals'       => $this->totals($from, $to),
            'top_tracks'   => $this->topTracks($year, 10),
            'top_artists'  => $this->topArtists($from, $to),
            'top_albums'   => $this->topAlbums($from, $to),
            'top_genres'   => $this->topGenres($from, $to),
            'by_month'     => $byMonth,
            'by_month_max' => max(1, ...array_column($byMonth, 'plays')),
            'by_hour'      => $byHour,
            'by_hour_max'  => max(1, ...$byHour),
            'peak_day'     => $this->peakDay($from, $to),
            'obsession'    => $this->obsession($from, $to),
            'streak'       => self::longestStreak($this->listeningDays($from, $to)),
            'daypart'      => self::daypart($byHour),
            'liked'        => $this->likedCount($from, $to),
        ];
    }

    /**
     * The year's most-played tracks as full queue rows (MusicService's
     * shared row shape, `plays` included), most played first. Feeds both the
     * Top Tracks list and the "play your top tracks" queue.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topTracks(int $year, int $limit = self::MIX_SIZE): array
    {
        [$from, $to] = self::bounds($year);

        $rows = db()->fetchAll(
            "SELECT " . MusicService::TRACK_COLUMNS . ", " . MusicService::LIKED_COLUMN . ",
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id " . MusicService::TRACK_JOINS . "
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY t.id
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, client()->id, $from, $to, $limit],
        );

        return $this->music->mapTrackRows($rows);
    }

    /**
     * Longest run of consecutive calendar days with at least one play.
     * Null below two days — a one-day "streak" isn't worth a callout.
     *
     * @param list<string> $days Y-m-d strings, any order, duplicates allowed
     * @return array{days:int,from:string,to:string}|null
     */
    public static function longestStreak(array $days): ?array
    {
        $days = array_values(array_unique($days));
        sort($days);

        $best = null;
        $runStart = $prev = null;
        $run = 0;
        foreach ($days as $d) {
            $day = new \DateTimeImmutable($d);
            if ($prev !== null && $prev->modify('+1 day')->format('Y-m-d') === $day->format('Y-m-d')) {
                $run++;
            } else {
                $runStart = $day;
                $run = 1;
            }
            // >= keeps the most recent of equally long streaks.
            if ($best === null || $run >= $best['days']) {
                $best = ['days' => $run, 'start' => $runStart, 'end' => $day];
            }
            $prev = $day;
        }

        if ($best === null || $best['days'] < 2) {
            return null;
        }

        return [
            'days' => $best['days'],
            'from' => $best['start']->format('M j'),
            'to'   => $best['end']->format('M j'),
        ];
    }

    /**
     * Which part of the day dominates the listening clock, with its share of
     * plays and the single busiest hour. Null with no plays.
     *
     * @param array<int,int> $byHour plays keyed by hour 0–23
     * @return array{key:string,label:string,icon:string,blurb:string,share:int,peak_hour:string}|null
     */
    public static function daypart(array $byHour): ?array
    {
        $total = array_sum($byHour);
        if ($total <= 0) {
            return null;
        }

        $bestKey = null;
        $bestPlays = -1;
        foreach (self::DAYPARTS as $key => $part) {
            $plays = 0;
            foreach ($part['hours'] as $h) {
                $plays += $byHour[$h] ?? 0;
            }
            if ($plays > $bestPlays) {
                $bestKey = $key;
                $bestPlays = $plays;
            }
        }

        $peak = (int) array_search(max($byHour), $byHour, true);
        $part = self::DAYPARTS[$bestKey];

        return [
            'key'       => $bestKey,
            'label'     => $part['label'],
            'icon'      => $part['icon'],
            'blurb'     => $part['blurb'],
            'share'     => (int) round($bestPlays / $total * 100),
            'peak_hour' => self::hourLabel($peak),
        ];
    }

    /** 0 → "12 am", 13 → "1 pm". */
    public static function hourLabel(int $hour): string
    {
        $h = $hour % 12 === 0 ? 12 : $hour % 12;
        return $h . ($hour < 12 ? ' am' : ' pm');
    }

    /** @return array{0:string,1:string} half-open [from, to) for a calendar year */
    private static function bounds(int $year): array
    {
        return [
            sprintf('%d-01-01 00:00:00', $year),
            sprintf('%d-01-01 00:00:00', $year + 1),
        ];
    }

    /**
     * When this client's play history begins, if that's inside $year.
     * Null when the history predates the year (a full year of data) or
     * there is none.
     *
     * @return array{date:string,month:int}|null
     */
    private function trackedSince(int $year): ?array
    {
        $first = db()->fetch(
            "SELECT MIN(created_at) AS first FROM track_plays WHERE client_id = ?",
            [client()->id],
        )['first'] ?? null;

        if (!$first) {
            return null;
        }

        $first = new \DateTimeImmutable((string) $first);
        if ((int) $first->format('Y') !== $year) {
            return null;
        }

        return ['date' => $first->format('F j'), 'month' => (int) $first->format('n')];
    }

    /** @return list<string> distinct Y-m-d days with a counted play */
    private function listeningDays(string $from, string $to): array
    {
        $rows = db()->fetchAll(
            "SELECT DISTINCT DATE(tp.created_at) AS d
             FROM track_plays tp
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED,
            [client()->id, $from, $to],
        );

        return array_map(fn($r) => (string) $r['d'], $rows);
    }

    /** Tracks hearted during the window that are still liked now. */
    private function likedCount(string $from, string $to): int
    {
        $row = db()->fetch(
            "SELECT COUNT(*) AS n FROM track_likes
             WHERE client_id = ? AND created_at >= ? AND created_at < ?",
            [client()->id, $from, $to],
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * @return array{plays:int,minutes:int,hours:int,tracks:int,artists:int,albums:int,days:int}
     */
    private function totals(string $from, string $to): array
    {
        $ns = MusicService::NOT_SKIPPED;

        // Skip-aware counts, but listened time keeps every play's ms_played —
        // a skipped track still contributes the seconds actually heard. Rows
        // predating skip-tracking (NULL ms_played) fall back to full length.
        $row = db()->fetch(
            "SELECT SUM(CASE WHEN $ns THEN 1 ELSE 0 END) AS plays,
                    COALESCE(SUM(COALESCE(tp.ms_played, tm.length_ms)), 0) AS listened_ms,
                    COUNT(DISTINCT CASE WHEN $ns THEN tp.track_id END) AS tracks,
                    COUNT(DISTINCT CASE WHEN $ns THEN t.track_artist_id END) AS artists,
                    COUNT(DISTINCT CASE WHEN $ns THEN t.album_id END) AS albums,
                    COUNT(DISTINCT CASE WHEN $ns THEN DATE(tp.created_at) END) AS days
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?",
            [client()->id, $from, $to],
        );

        $minutes = intdiv((int) ($row['listened_ms'] ?? 0), 60000);

        return [
            'plays'   => (int) ($row['plays'] ?? 0),
            'minutes' => $minutes,
            'hours'   => intdiv($minutes, 60),
            'tracks'  => (int) ($row['tracks'] ?? 0),
            'artists' => (int) ($row['artists'] ?? 0),
            'albums'  => (int) ($row['albums'] ?? 0),
            'days'    => (int) ($row['days'] ?? 0),
        ];
    }

    private function topArtists(string $from, string $to, int $limit = 5): array
    {
        return db()->fetchAll(
            "SELECT ar.hash AS hash,
                    ar.name AS name,
                    ar.image AS image,
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY ar.id
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, $from, $to, $limit],
        );
    }

    private function topAlbums(string $from, string $to, int $limit = 6): array
    {
        return db()->fetchAll(
            "SELECT al.hash AS hash,
                    al.title AS title,
                    al.cover AS cover,
                    ar.name AS artist,
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = al.artist_id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY al.id
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, $from, $to, $limit],
        );
    }

    private function topGenres(string $from, string $to, int $limit = 5): array
    {
        return db()->fetchAll(
            "SELECT al.genre AS genre,
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
               AND al.genre <> '' AND al.genre <> 'Unknown'
             GROUP BY al.genre
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, $from, $to, $limit],
        );
    }

    /**
     * Play counts per calendar month, keyed 1–12 (zero-filled), plus the
     * month-name label the view needs for the bars.
     *
     * @return array<int,array{month:string,plays:int}>
     */
    private function playsByMonth(string $from, string $to): array
    {
        $rows = db()->fetchAll(
            "SELECT MONTH(tp.created_at) AS m, COUNT(*) AS plays
             FROM track_plays tp
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY m",
            [client()->id, $from, $to],
        );

        $counts = array_fill(1, 12, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['m']] = (int) $row['plays'];
        }

        $out = [];
        foreach ($counts as $m => $plays) {
            $out[] = ['month' => self::MONTHS[$m], 'plays' => $plays];
        }
        return $out;
    }

    /**
     * Play counts per hour of day, keyed 0–23 (zero-filled).
     *
     * @return array<int,int>
     */
    private function playsByHour(string $from, string $to): array
    {
        $rows = db()->fetchAll(
            "SELECT HOUR(tp.created_at) AS h, COUNT(*) AS plays
             FROM track_plays tp
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY h",
            [client()->id, $from, $to],
        );

        $counts = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['h']] = (int) $row['plays'];
        }
        return $counts;
    }

    /** @return array{date:string,plays:int}|null */
    private function peakDay(string $from, string $to): ?array
    {
        $row = db()->fetch(
            "SELECT DATE(tp.created_at) AS d, COUNT(*) AS plays
             FROM track_plays tp
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY d
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT 1",
            [client()->id, $from, $to],
        );

        if (!$row) {
            return null;
        }

        return [
            'date'  => (new \DateTimeImmutable((string) $row['d']))->format('F j'),
            'plays' => (int) $row['plays'],
        ];
    }

    /**
     * The single artist-month with the most plays — "your March was all
     * Nick Cave". Null when there's no data.
     *
     * @return array{artist:string,hash:string,month:string,plays:int}|null
     */
    private function obsession(string $from, string $to): ?array
    {
        $row = db()->fetch(
            "SELECT ar.name AS artist,
                    ar.hash AS hash,
                    MONTH(tp.created_at) AS m,
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY ar.id, m
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT 1",
            [client()->id, $from, $to],
        );

        if (!$row) {
            return null;
        }

        return [
            'artist' => (string) $row['artist'],
            'hash'   => (string) $row['hash'],
            'month'  => self::MONTHS[(int) $row['m']],
            'plays'  => (int) $row['plays'],
        ];
    }
}
