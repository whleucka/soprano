<?php

namespace App\Services\Soprano;

use App\Models\{Album, Artist, Track, TrackPlay};

class MusicService
{
    public function getTrack(string $hash): ?Track
    {
        return Track::where('hash', $hash)->first();
    }

    public function getAlbumByHash(string $hash): ?Album
    {
        return Album::where('hash', $hash)->first();
    }

    public function getArtistByHash(string $hash): ?Artist
    {
        return Artist::where('hash', $hash)->first();
    }

    public function trackPlay(int $trackId, ?int $clientId): void
    {
        TrackPlay::create([
            'track_id'  => $trackId,
            'client_id' => $clientId,
        ]);
    }

    public function albumTracks(int $albumId): array
    {
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
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
             WHERE t.album_id = ?
             ORDER BY CAST(tm.track_number AS UNSIGNED), tm.track_number",
            [$albumId],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function discography(int $artistId, int $limit = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.artist_id = ?
             ORDER BY al.year DESC, al.title ASC
             LIMIT ?",
            [$artistId, $limit],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function artistTracks(string $artistHash): array
    {
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
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
             WHERE ar.hash = ?
             GROUP BY t.id
             ORDER BY tm.title ASC",
            [$artistHash],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function topTracksByArtist(int $artistId, int $limit = 10): array
    {
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist,
                    tm.title AS title,
                    tm.track_number AS track_number,
                    tm.playtime_string AS playtime_string,
                    COUNT(tp.id) AS plays
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             JOIN track_plays tp ON tp.track_id = t.id
             WHERE t.artist_id = ?
             GROUP BY t.id
             ORDER BY plays DESC, tm.title ASC
             LIMIT ?",
            [$artistId, $limit],
        );

        return array_map(function (array $row) {
            $entry = $this->mapTrackRow($row);
            $entry['plays'] = (int) $row['plays'];
            return $entry;
        }, $rows);
    }

    public function recentlyAdded(int $albumCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
                    al.year AS year,
                    ar.hash AS artist_hash,
                    ar.name AS artist
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             ORDER BY al.id DESC
             LIMIT ?",
            [$albumCount],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function recentlyPlayed(int $trackCount = 50): array
    {
        $since = (new \DateTime('- 1 WEEK'))->format('Y-m-d H:i:s');

        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
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
             LIMIT ?",
            [$since, $trackCount],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function topPlayed(int $trackCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash,
                    al.hash AS album_hash,
                    al.title AS album,
                    al.cover AS cover,
                    al.dominant_color AS dominant_color,
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
             LIMIT ?",
            [$trackCount],
        );

        return array_map(function (array $row) {
            $entry = $this->mapTrackRow($row);
            $entry['plays'] = (int) $row['plays'];
            return $entry;
        }, $rows);
    }

    private function mapTrackRow(array $row): array
    {
        return [
            'hash'            => $row['track_hash'] ?? null,
            'album_hash'      => $row['album_hash'] ?? null,
            'artist_hash'     => $row['artist_hash'] ?? null,
            'title'           => $row['title'] ?? '',
            'artist'          => $row['artist'] ?? '',
            'album'           => $row['album'] ?? '',
            'cover'           => $row['cover'] ?? '/images/no-album-art.png',
            'dominant_color'  => $row['dominant_color'] ?? null,
            'year'            => $row['year'] ?? '',
            'track_number'    => $row['track_number'] ?? '',
            'playtime_string' => $row['playtime_string'] ?? '',
        ];
    }
}
