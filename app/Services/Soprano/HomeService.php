<?php

namespace App\Services\Soprano;

class HomeService
{
    public function recentlyAdded(int $albumCount = 50): array
    {
        $albumCount = (int) $albumCount;
        $rows = db()->fetchAll(
            "SELECT al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             ORDER BY al.id DESC
             LIMIT $albumCount",
        );

        return array_map(fn($row) => [
            'album_hash'  => $row['album_hash'],
            'artist_hash' => $row['artist_hash'],
            'album'       => $row['album'],
            'artist'      => $row['artist'],
            'cover'       => $row['cover'],
            'year'        => $row['year'],
        ], $rows);
    }

    public function recentlyPlayed(int $trackCount = 20): array
    {
        $trackCount = (int) $trackCount;
        $since = (new \DateTime('- 1 WEEK'))->format('Y-m-d H:i:s');

        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist,
                    tm.title AS title,
                    c.username AS client,
                    MAX(tp.id) AS last_play_id
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             LEFT JOIN clients c ON c.id = tp.client_id
             WHERE tp.created_at > ?
             GROUP BY t.id
             ORDER BY last_play_id DESC
             LIMIT $trackCount",
            [$since],
        );

        return array_map(fn($row) => [
            'hash'        => $row['track_hash'],
            'album_hash'  => $row['album_hash'],
            'artist_hash' => $row['artist_hash'],
            'client'      => $row['client'],
            'title'       => $row['title'] ?? '',
            'artist'      => $row['artist'],
            'album'       => $row['album'],
            'cover'       => $row['cover'],
            'year'        => $row['year'],
        ], $rows);
    }

    public function topPlayed(int $trackCount = 20): array
    {
        $trackCount = (int) $trackCount;
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist,
                    tm.title AS title,
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             GROUP BY t.id
             ORDER BY plays DESC, last_play_id DESC
             LIMIT $trackCount",
        );

        return array_map(fn($row) => [
            'hash'        => $row['track_hash'],
            'album_hash'  => $row['album_hash'],
            'artist_hash' => $row['artist_hash'],
            'title'       => $row['title'] ?? '',
            'artist'      => $row['artist'],
            'album'       => $row['album'],
            'cover'       => $row['cover'],
            'year'        => $row['year'],
            'plays'       => (int) $row['plays'],
        ], $rows);
    }
}
