<?php

namespace App\Services\Admin;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Client;
use App\Models\RadioStation;
use App\Models\Track;
use App\Models\TrackLike;
use App\Models\TrackPlay;

/**
 * Soprano-specific dashboard data.
 *
 * Mirrors DashboardService conventions: request-level caching, raw SQL for
 * GROUP BY / aggregate queries, ORM for simple counts.
 */
class MusicStatsService
{
    private array $cache = [];

    private function cached(string $key, callable $callback): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $callback();
        }
        return $this->cache[$key];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    /**
     * Library totals for the quick-stats widget.
     */
    public function getLibraryStats(): array
    {
        return $this->cached('library_stats', fn() => [
            'tracks' => Track::countAll(),
            'artists' => Artist::countAll(),
            'albums' => Album::countAll(),
            'radio_stations' => RadioStation::countAll(),
            'plays' => TrackPlay::countAll(),
            'likes' => TrackLike::countAll(),
            'clients' => Client::countAll(),
            'new_clients' => Client::where(
                'created_at',
                '>=',
                $this->now()->modify('-30 days')->format('Y-m-d H:i:s')
            )->count(),
        ]);
    }

    /**
     * Most-played tracks.
     */
    public function getTopTracks(int $limit = 5): array
    {
        $limit = max(1, $limit);
        return db()->fetchAll(
            "SELECT tm.title AS title, ar.name AS artist, COUNT(tp.id) AS plays
             FROM tracks t
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             LEFT JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_plays tp ON tp.track_id = t.id
             GROUP BY t.id
             HAVING plays > 0
             ORDER BY plays DESC, tm.title ASC
             LIMIT " . (int)$limit
        );
    }

    /**
     * Artists ranked by total plays across their tracks.
     */
    public function getTopArtists(int $limit = 5): array
    {
        $limit = max(1, $limit);
        return db()->fetchAll(
            "SELECT ar.name AS artist, ar.image AS image, COUNT(tp.id) AS plays
             FROM artists ar
             LEFT JOIN tracks t ON t.artist_id = ar.id
             LEFT JOIN track_plays tp ON tp.track_id = t.id
             GROUP BY ar.id
             HAVING plays > 0
             ORDER BY plays DESC, ar.name ASC
             LIMIT " . (int)$limit
        );
    }

    /**
     * Most recently added tracks.
     */
    public function getRecentlyAdded(int $limit = 5): array
    {
        $limit = max(1, $limit);
        $rows = db()->fetchAll(
            "SELECT tm.title AS title, ar.name AS artist, t.created_at AS created_at
             FROM tracks t
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             LEFT JOIN artists ar ON ar.id = t.artist_id
             ORDER BY t.id DESC
             LIMIT " . (int)$limit
        );

        return array_map(fn(array $row) => [
            'title' => $row['title'] ?? 'Unknown',
            'artist' => $row['artist'] ?? 'Unknown',
            'time_ago' => $this->timeAgo($row['created_at']),
        ], $rows);
    }

    /**
     * Track plays per day for the current ISO week (Mon–Sun), shaped for
     * dashboard-chart.html.twig (same contract as DashboardService charts).
     */
    public function getPlaysChart(): array
    {
        $now = $this->now();

        $dayOfWeek = (int)$now->format('N'); // 1=Mon, 7=Sun
        $weekStart = $now->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');
        $weekEnd = $now->modify('+' . (7 - $dayOfWeek) . ' days')->setTime(23, 59, 59)
            ->format('Y-m-d H:i:s');

        $data = db()->fetchAll(
            "SELECT
                MIN(DAYNAME(created_at)) AS day_name,
                DATE(created_at) AS day_date,
                COUNT(*) AS total
            FROM track_plays
            WHERE created_at BETWEEN ? AND ?
            GROUP BY day_date
            ORDER BY day_date",
            [$weekStart, $weekEnd]
        );

        $labels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $payload = array_fill(0, 7, 0);

        foreach ($data as $row) {
            $index = array_search($row['day_name'], $labels);
            if ($index !== false) {
                $payload[$index] = (int)$row['total'];
            }
        }

        return [
            'options' => json_encode([
                'type' => 'bar',
                'data' => (object)[
                    'labels' => $labels,
                    'datasets' => [
                        (object)[
                            'label' => 'Plays',
                            'data' => $payload,
                            'backgroundColor' => 'rgba(168, 85, 247, 0.8)',
                            'borderColor' => '#a855f7',
                            'borderWidth' => 0,
                            'borderRadius' => 6,
                            'hoverBackgroundColor' => '#9333ea',
                        ]
                    ]
                ],
                'options' => (object)[
                    'responsive' => true,
                    'maintainAspectRatio' => false,
                    'plugins' => (object)[
                        'legend' => (object)[
                            'display' => false,
                        ],
                    ],
                    'scales' => (object)[
                        'y' => (object)[
                            'beginAtZero' => true,
                            'grid' => (object)[
                                'color' => 'rgba(0, 0, 0, 0.05)',
                            ],
                        ],
                        'x' => (object)[
                            'grid' => (object)[
                                'display' => false,
                            ],
                        ],
                    ],
                ],
            ]),
        ];
    }

    /**
     * Human-friendly relative time.
     */
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
            $count = floor($diff / $seconds);
            if ($count >= 1) {
                return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }
}
