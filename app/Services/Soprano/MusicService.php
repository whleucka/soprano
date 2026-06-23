<?php

namespace App\Services\Soprano;

use App\Models\{Album, Artist, Track, TrackLike, TrackPlay};

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

    /**
     * Hero stats for an artist: how many albums and tracks of theirs are in
     * the library and the total runtime of all their tracks (e.g. "3h 42m").
     *
     * @return array{album_count: int, track_count: int, runtime: string}
     */
    public function getArtistStats(int $artistId): array
    {
        $row = db()->fetch(
            "SELECT
                (SELECT COUNT(*) FROM albums WHERE artist_id = ?) AS album_count,
                (SELECT COUNT(*) FROM tracks WHERE artist_id = ?) AS track_count,
                (SELECT SUM(tm.length_ms)
                 FROM tracks t
                 LEFT JOIN track_meta tm ON tm.track_id = t.id
                 WHERE t.artist_id = ?) AS total_length_ms",
            [$artistId, $artistId, $artistId],
        );

        return [
            'album_count' => (int) ($row['album_count'] ?? 0),
            'track_count' => (int) ($row['track_count'] ?? 0),
            'runtime'     => $this->formatDuration((int) ($row['total_length_ms'] ?? 0)),
        ];
    }

    /**
     * Format a millisecond duration as a compact "Xh Ym" / "Ym" string.
     */
    private function formatDuration(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        $hours   = intdiv($minutes, 60);
        $minutes %= 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    public function trackPlay(int $trackId): void
    {
        TrackPlay::create([
            'track_id'  => $trackId,
            'client_id' => client()->id,
        ]);
    }

    public function isTrackLiked(string $hash): bool
    {
        $track = $this->getTrack($hash);
        $trackId = $track->id;
        $clientId = client()->id;
        $like = TrackLike::where("track_id", $trackId)
            ->andWhere("client_id", $clientId)->first();

        if ($like) {
            return true;
        } 
        return false;
    }

    public function toggleTrackLike(string $hash)
    {
        $track = $this->getTrack($hash);
        $trackId = $track->id;
        $clientId = client()->id;
        $like = TrackLike::where("track_id", $trackId)
            ->andWhere("client_id", $clientId)->first();

        if ($like) {
            $like->delete();
        } else {
            TrackLike::create([
                'track_id'  => $trackId,
                'client_id' => $clientId,
            ]);
        }
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
                    tm.playtime_string AS playtime_string,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE t.album_id = ?
             ORDER BY CAST(tm.track_number AS UNSIGNED), tm.track_number",
            [client()->id, $albumId],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    /**
     * Find likely duplicate tracks, grouped two ways:
     *   - within_album: same album + same title (e.g. a "(1)" or "_<ticks>" copy)
     *   - cross_album:  same artist + same title spanning 2+ distinct albums
     *                   (e.g. the same song in two MusicBrainz editions)
     *
     * Report only — nothing is deleted. Each group is an array of raw rows so
     * the caller can show path/length and let a human decide.
     *
     * @return object{within_album: array<int,array<int,array>>, cross_album: array<int,array<int,array>>}
     */
    public function findDuplicateTracks(): object
    {
        $rows = db()->fetchAll(
            "SELECT t.id AS track_id,
                    t.album_id,
                    t.artist_id,
                    t.pathname,
                    ar.name AS artist,
                    al.title AS album,
                    tm.title AS title,
                    tm.length_ms AS length_ms,
                    tm.playtime_string AS playtime_string
             FROM tracks t
             JOIN artists ar ON ar.id = t.artist_id
             JOIN albums al ON al.id = t.album_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             ORDER BY ar.name, al.title, tm.title"
        );

        $byAlbumTitle  = [];
        $byArtistTitle = [];

        foreach ($rows as $row) {
            $title = $this->normalizeTitle((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $byAlbumTitle[$row['album_id'] . '|' . $title][]   = $row;
            $byArtistTitle[$row['artist_id'] . '|' . $title][] = $row;
        }

        // Same album + title, 2+ tracks.
        $withinAlbum = array_values(array_filter(
            $byAlbumTitle,
            static fn(array $group): bool => count($group) > 1,
        ));

        // Same artist + title across 2+ distinct albums.
        $crossAlbum = [];
        foreach ($byArtistTitle as $group) {
            $albumIds = array_unique(array_map(static fn(array $r) => $r['album_id'], $group));
            if (count($albumIds) > 1) {
                $crossAlbum[] = $group;
            }
        }

        return (object) [
            'within_album' => $withinAlbum,
            'cross_album'  => $crossAlbum,
        ];
    }

    private function normalizeTitle(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /**
     * Remove tracks: move each file to the trash folder, then delete the DB row
     * (track_meta and track_plays cascade). Empty albums/artists are pruned
     * afterwards. Files are recoverable from the trash path until cleared by
     * hand. Returns a summary of what happened.
     *
     * @param array<int,int> $trackIds
     * @return object{trashed_files: int, deleted_rows: int, missing_files: array<int,string>, trash_path: string}
     */
    public function removeTracks(array $trackIds): object
    {
        $trashPath = rtrim((string) config('soprano.trash_path'), '/');

        $trashedFiles = 0;
        $deletedRows  = 0;
        $missing      = [];

        foreach ($trackIds as $id) {
            $track = Track::find((string) $id);
            if (!$track) {
                continue;
            }

            $pathname = (string) ($track->pathname ?? '');
            if ($pathname !== '') {
                if (is_file($pathname)) {
                    if ($this->trashFile($pathname, $trashPath, (int) $id)) {
                        $trashedFiles++;
                    }
                } else {
                    $missing[] = $pathname;
                }
            }

            if ($track->delete()) {
                $deletedRows++;
            }
        }

        if ($deletedRows > 0) {
            $this->pruneEmptyAlbumsAndArtists();
        }

        return (object) [
            'trashed_files' => $trashedFiles,
            'deleted_rows'  => $deletedRows,
            'missing_files' => $missing,
            'trash_path'    => $trashPath,
        ];
    }

    /**
     * Move a file into the trash folder. The track id prefixes the basename so
     * copies that share a filename across albums never collide.
     */
    private function trashFile(string $pathname, string $trashPath, int $trackId): bool
    {
        if ($trashPath === '') {
            return false;
        }
        if (!is_dir($trashPath) && !@mkdir($trashPath, 0775, true) && !is_dir($trashPath)) {
            return false;
        }

        $dest = $trashPath . '/' . $trackId . '__' . basename($pathname);

        return @rename($pathname, $dest);
    }

    /**
     * Remove albums left with no tracks, then artists left with no tracks
     * and no albums. Mirrors SyncService's orphan cleanup.
     */
    private function pruneEmptyAlbumsAndArtists(): void
    {
        $db = db();
        $db->execute("DELETE FROM albums WHERE id NOT IN (SELECT DISTINCT album_id FROM tracks)");
        $db->execute(
            "DELETE FROM artists WHERE id NOT IN (SELECT DISTINCT artist_id FROM tracks)
             AND id NOT IN (SELECT DISTINCT artist_id FROM albums)"
        );
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

    public function randomTracks($limit = 2500): array
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
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
            ORDER BY RAND()
            LIMIT ?",
            [client()->id, $limit]
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function likedTracks($limit = 2500): array
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
                    1 AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             JOIN track_likes tl ON tl.track_id = t.id AND tl.client_id=?
             LEFT JOIN track_meta tm ON tm.track_id = t.id
            ORDER BY ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
            LIMIT ?",
            [client()->id, $limit]
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
                    tm.playtime_string AS playtime_string,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE ar.hash = ?
             GROUP BY t.id
             ORDER BY al.hash, CAST(tm.track_number AS UNSIGNED), tm.track_number",
            [client()->id, $artistHash],
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
                    COUNT(tp.id) AS plays,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             JOIN track_plays tp ON tp.track_id = t.id
             WHERE t.artist_id = ?
             GROUP BY t.id
             ORDER BY plays DESC, tm.title ASC
             LIMIT ?",
            [client()->id, $artistId, $limit],
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

    public function recentlyAddedTracks(int $albumCount = 50): array
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
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
            FROM tracks t
            JOIN albums al ON al.id = t.album_id
            JOIN artists ar ON ar.id = t.artist_id
            LEFT JOIN track_meta tm ON tm.track_id = t.id
            ORDER BY t.id DESC
            LIMIT ?",
            [client()->id, $albumCount],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    function timeAgo(string $datetime): string {
        $now  = new \DateTime();
        $past = new \DateTime($datetime);
        $diff = $now->diff($past);

        $intervals = [
            ['year',   $diff->y],
            ['month',  $diff->m],
            ['week',   (int)($diff->days / 7)],
            ['day',    $diff->d % 7],
            ['hour',   $diff->h],
            ['minute', $diff->i],
            ['second', $diff->s],
        ];

        foreach ($intervals as [$unit, $value]) {
            if ($value >= 1) {
                return $value . ' ' . $unit . ($value > 1 ? 's' : '') . ' ago';
            }
        }

        return 'just now';
    }

    public function recentlyPlayed(int $trackCount = 1000): array
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
                    tm.playtime_string AS playtime_string,
                    c.username AS client,
                    MAX(tp.id) AS last_play_id,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
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
            [client()->id, $since, $trackCount],
        );

        return array_map(function($row) {
            $entry = $this->mapTrackRow($row);
            $tp = TrackPlay::find($row['last_play_id']);
            $entry['ago'] = $this->timeAgo($tp->created_at);
            return $entry;
        }, $rows);
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
                    tm.playtime_string AS playtime_string,
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ?
             GROUP BY t.id
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $trackCount],
        );

        return array_map(function (array $row) {
            $entry = $this->mapTrackRow($row);
            $entry['plays'] = (int) $row['plays'];
            return $entry;
        }, $rows);
    }

    public function topPlayedThisMonth(int $trackCount = 50): array
    {
        $since = (new \DateTime('- 1 MONTH'))->format('Y-m-d H:i:s');

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
                    tm.playtime_string AS playtime_string,
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ? AND tp.created_at > ?
             GROUP BY t.id
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $since, $trackCount],
        );

        return array_map(function (array $row) {
            $entry = $this->mapTrackRow($row);
            $entry['plays'] = (int) $row['plays'];
            return $entry;
        }, $rows);
    }

    public function rediscover(int $trackCount = 50): array
    {
        $since = (new \DateTime('- 30 DAY'))->format('Y-m-d H:i:s');

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
                    tm.playtime_string AS playtime_string,
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id,
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ?
             GROUP BY t.id
             HAVING COUNT(tp.id) >= 3 AND MAX(tp.created_at) < ?
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $since, $trackCount],
        );

        return array_map(function (array $row) {
            $entry = $this->mapTrackRow($row);
            $entry['plays'] = (int) $row['plays'];
            return $entry;
        }, $rows);
    }

    public function recentlyLiked(int $trackCount = 50): array
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
                    tm.playtime_string AS playtime_string,
                    1 AS liked
             FROM track_likes tl
             JOIN tracks t ON t.id = tl.track_id
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tl.client_id = ?
             ORDER BY tl.id DESC
             LIMIT ?",
            [client()->id, $trackCount],
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    /**
     * Distinct genres for the browse grid, with an album count and a
     * representative dominant colour for the tile. Skips untagged albums
     * ('' / 'Unknown').
     *
     * @return array<int,array{genre: string, count: int, color: ?string}>
     */
    public function genres(): array
    {
        $rows = db()->fetchAll(
            "SELECT genre,
                    COUNT(*) AS cnt
             FROM albums
             WHERE genre <> '' AND genre <> 'Unknown'
             GROUP BY genre
             ORDER BY cnt DESC, genre ASC"
        );

        return array_map(fn($row, $i) => [
            'genre' => $row['genre'],
            'count' => (int) $row['cnt'],
            'color' => $this->browseColor($i),
        ], $rows, array_keys($rows));
    }

    /**
     * Vibrant, distinct tile colour for the browse grid, picked by position so
     * adjacent tiles never repeat until the palette is exhausted. All colours
     * are saturated mid-tones that stay legible under white text.
     */
    private function browseColor(int $index): string
    {
        $palette = [
            '#e13300', '#1db954', '#7358ff', '#b02897', '#0d73ec',
            '#d84000', '#006450', '#dc148c', '#8d67ab', '#148a08',
            '#1e3264', '#ba5d07', '#e8115b', '#477d95', '#608108',
            '#503750',
        ];

        return $palette[$index % count($palette)];
    }

    /**
     * Distinct decades derived from album year, with an album count and a
     * representative colour. Only albums with a 4-digit year are counted.
     *
     * @return array<int,array{decade: int, count: int, color: ?string}>
     */
    public function decades(): array
    {
        $rows = db()->fetchAll(
            "SELECT (CAST(year AS UNSIGNED) DIV 10 * 10) AS decade,
                    COUNT(*) AS cnt
             FROM albums
             WHERE year REGEXP '^[0-9]{4}$'
             GROUP BY decade
             ORDER BY decade DESC"
        );

        return array_map(fn($row, $i) => [
            'decade' => (int) $row['decade'],
            'count'  => (int) $row['cnt'],
            'color'  => $this->browseColor($i),
        ], $rows, array_keys($rows));
    }

    public function tracksByGenre(string $genre, int $limit = 2500): array
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
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE al.genre = ?
             ORDER BY ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $genre, $limit]
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function tracksByDecade(int $decade, int $limit = 2500): array
    {
        $album_title = $decade < 2000 ? substr($decade, 2, 2) . "s"  : $decade . "s";
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
                    IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             JOIN artists ar ON ar.id = t.artist_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE (al.year REGEXP '^[0-9]{4}$'
               AND CAST(al.year AS UNSIGNED) BETWEEN ? AND ?
               OR al.title LIKE ?)
             ORDER BY al.year ASC, ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $decade, $decade + 9, "%$album_title%", $limit]
        );

        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
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
            'client'          => $row['client'] ?? null,
            'liked'           => (int) ($row['liked'] ?? 0),
        ];
    }
}
