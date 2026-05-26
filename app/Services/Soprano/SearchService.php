<?php

namespace App\Services\Soprano;

class SearchService
{
    public function setSearch(string $term): void
    {
        if ($term) {
            session()->set('search', [
                'tracks' => $this->search($term),
                'term'   => $term,
            ]);
        } else {
            session()->set('search', [
                'tracks' => [],
                'term'   => '',
            ]);
        }
    }

    public function search(string $term): array
    {
        $like = "%$term%";
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist,
                    tm.title AS title,
                    tm.track_number AS track_number,
                    tm.playtime_string AS playtime_string
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE ar.name LIKE ? OR al.title LIKE ? OR tm.title LIKE ?
             ORDER BY ar.name, al.title, CAST(tm.track_number AS UNSIGNED)",
            [$like, $like, $like],
        );

        return array_map(fn($row) => [
            'hash'            => $row['track_hash'],
            'album_hash'      => $row['album_hash'],
            'artist_hash'     => $row['artist_hash'],
            'title'           => $row['title'] ?? '',
            'artist'          => $row['artist'],
            'album'           => $row['album'],
            'cover'           => $row['cover'] ?? '/images/no-album-art.png',
            'year'            => $row['year'],
            'track_number'    => $row['track_number'] ?? '',
            'playtime_string' => $row['playtime_string'] ?? '',
        ], $rows);
    }

    public function getSearch()
    {
        return session()->get('search');
    }
}
