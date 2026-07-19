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
    private const MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
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
        $from = sprintf('%d-01-01 00:00:00', $year);
        $to   = sprintf('%d-01-01 00:00:00', $year + 1);

        $byMonth = $this->playsByMonth($from, $to);
        $byHour  = $this->playsByHour($from, $to);

        return [
            'year'         => $year,
            'totals'       => $this->totals($from, $to),
            'top_tracks'   => $this->topTracks($from, $to),
            'top_artists'  => $this->topArtists($from, $to),
            'top_albums'   => $this->topAlbums($from, $to),
            'top_genres'   => $this->topGenres($from, $to),
            'by_month'     => $byMonth,
            'by_month_max' => max(1, ...array_column($byMonth, 'plays')),
            'by_hour'      => $byHour,
            'by_hour_max'  => max(1, ...$byHour),
            'peak_day'     => $this->peakDay($from, $to),
            'obsession'    => $this->obsession($from, $to),
        ];
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

    private function topTracks(string $from, string $to, int $limit = 10): array
    {
        return db()->fetchAll(
            "SELECT t.hash AS hash,
                    tm.title AS title,
                    ar.name AS artist,
                    ar.hash AS artist_hash,
                    al.cover AS cover,
                    COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.track_artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ? AND tp.created_at >= ? AND tp.created_at < ?
               AND " . MusicService::NOT_SKIPPED . "
             GROUP BY t.id
             ORDER BY plays DESC, MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, $from, $to, $limit],
        );
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
             ORDER BY plays DESC
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
             ORDER BY plays DESC
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
             ORDER BY plays DESC
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
             ORDER BY plays DESC
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
             ORDER BY plays DESC
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
