<?php

namespace App\Services\Soprano;

use App\Models\{Album, Artist, Track, TrackLike, TrackPlay};

class MusicService
{
    /**
     * Shared SELECT fragments for the track feeds. Every feed returns the same
     * row shape (see mapTrackRow); queries differ only in their FROM/WHERE/
     * ORDER BY and any extra aggregate columns (plays, last_played_at, …).
     * The liked column is a per-client subquery and binds client()->id as its
     * FIRST placeholder, so params for feeds using it start with the client id.
     */
    public const TRACK_COLUMNS =
        "t.hash AS track_hash,
         al.hash AS album_hash,
         al.title AS album,
         al.cover AS cover,
         al.dominant_color AS dominant_color,
         al.year AS year,
         ar.hash AS artist_hash,
         ar.name AS artist,
         tm.title AS title,
         tm.track_number AS track_number,
         tm.playtime_string AS playtime_string";

    public const LIKED_COLUMN =
        "IFNULL((SELECT 1 FROM track_likes WHERE client_id=? AND track_id=t.id), 0) AS liked";

    /**
     * Confirmed skips don't count as listens in the play-count feeds. NULL
     * (pre-tracking rows and unreported plays) counts as played — same
     * semantics as AutoPlaylistService.
     */
    public const NOT_SKIPPED = "(tp.skipped IS NULL OR tp.skipped = 0)";

    /**
     * A play that's been closed out and confirmed a listen: 0 is a finalized
     * listen, NULL with ms_played set means finalized with unknown duration.
     * A bare NULL/NULL row is still in progress (or never reported) — the top
     * feeds ignore it so a track doesn't enter Top Played the moment it
     * starts, then vanish when it's skipped.
     */
    public const CLOSED_PLAY = "(tp.skipped = 0 OR (tp.skipped IS NULL AND tp.ms_played IS NOT NULL))";

    public const TRACK_JOINS =
        "JOIN albums al ON al.id = t.album_id
         JOIN artists ar ON ar.id = t.track_artist_id
         LEFT JOIN track_meta tm ON tm.track_id = t.id";

    /** Ids per statement when liking/unliking a whole collection at once. */
    private const LIKE_CHUNK = 500;

    private const ALBUM_COLUMNS =
        "al.hash AS album_hash,
         al.title AS album,
         al.cover AS cover,
         al.dominant_color AS dominant_color,
         al.year AS year,
         ar.hash AS artist_hash,
         ar.name AS artist";

    public function getTrack(string $hash): ?Track
    {
        return Track::where('hash', $hash)->first();
    }

    /**
     * Single track in the shared feed row shape — used to splice one
     * track into the play queue.
     */
    public function trackRow(string $hash): ?array
    {
        $row = db()->fetch(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE t.hash = ?",
            [client()->id, $hash],
        );

        return $row ? $this->mapTrackRow($row) : null;
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
                (SELECT COUNT(*) FROM albums al
                 WHERE al.artist_id = ?
                    OR EXISTS (SELECT 1 FROM tracks tx
                               WHERE tx.album_id = al.id AND tx.track_artist_id = ?)) AS album_count,
                (SELECT COUNT(*) FROM tracks WHERE track_artist_id = ?) AS track_count,
                (SELECT SUM(tm.length_ms)
                 FROM tracks t
                 LEFT JOIN track_meta tm ON tm.track_id = t.id
                 WHERE t.track_artist_id = ?) AS total_length_ms",
            [$artistId, $artistId, $artistId, $artistId],
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

    public function trackPlay(int $trackId, ?string $source = null): void
    {
        TrackPlay::create([
            'track_id'  => $trackId,
            'client_id' => client()->id,
            'source'    => $source,
        ]);
    }

    /**
     * Close out the latest play of a track for this client: record how far
     * playback got and whether it counts as a skip. A natural end never does;
     * a manual change does when under 80% listened (falling back to the
     * client-reported duration when length_ms is missing, unknown when both
     * are). ms_played only grows and skipped is recomputed from the new
     * position, so a play reported at pagehide that later resumes and
     * finishes flips 1 -> 0.
     */
    public function finalizeTrackPlay(string $hash, int $posMs, ?int $durMs, bool $natural): void
    {
        $row = db()->fetch(
            "SELECT tp.id, tp.ms_played, tm.length_ms
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ? AND t.hash = ?
             ORDER BY tp.id DESC
             LIMIT 1",
            [client()->id, $hash],
        );
        if (!$row) {
            return;
        }

        $msPlayed = max((int) ($row['ms_played'] ?? 0), $posMs, 0);
        $length   = $durMs ?: (int) ($row['length_ms'] ?? 0);
        $skipped  = $natural ? 0 : ($length > 0 ? (int) ($msPlayed < $length * 0.8) : null);

        db()->execute(
            "UPDATE track_plays SET ms_played = ?, skipped = ? WHERE id = ?",
            [$msPlayed, $skipped, $row['id']],
        );
    }

    public function isTrackLiked(string $hash): bool
    {
        $track = $this->getTrack($hash);
        if (!$track) {
            return false;
        }
        $like = TrackLike::where("track_id", $track->id)
            ->andWhere("client_id", client()->id)->first();

        return (bool) $like;
    }

    /**
     * Returns the new liked state.
     */
    public function toggleTrackLike(string $hash): bool
    {
        $track = $this->getTrack($hash);
        if (!$track) {
            return false;
        }
        $like = TrackLike::where("track_id", $track->id)
            ->andWhere("client_id", client()->id)->first();

        if ($like) {
            $like->delete();
            return false;
        }

        TrackLike::create([
            'track_id'  => $track->id,
            'client_id' => client()->id,
        ]);
        return true;
    }

    /**
     * All-or-nothing like state for an album / an artist's whole catalogue:
     * true only when every one of its tracks is liked (an empty collection
     * never is). Counted in SQL — the hearts re-render on every single-track
     * like, so they don't materialise the track rows to work it out.
     */
    public function albumFullyLiked(int $albumId): bool
    {
        return $this->fullyLiked("t.album_id = ?", [$albumId]);
    }

    public function artistFullyLiked(string $artistHash): bool
    {
        return $this->fullyLiked("ar.hash = ?", [$artistHash]);
    }

    /** Same joins as the track feeds, so the count covers exactly the tracks the toggle acts on. */
    private function fullyLiked(string $where, array $params): bool
    {
        $row = db()->fetch(
            "SELECT COUNT(DISTINCT t.id) AS total,
                    COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM track_likes tl
                                                     WHERE tl.track_id = t.id AND tl.client_id = ?)
                                        THEN t.id END) AS liked
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE $where",
            [client()->id, ...$params],
        );

        $total = (int) ($row['total'] ?? 0);

        return $total > 0 && (int) ($row['liked'] ?? 0) >= $total;
    }

    /**
     * Like every track in the selection, or unlike the lot when they already
     * all are — the collection heart's toggle. A part-liked collection fills
     * up first, so one more click is always what clears it.
     *
     * Returns the new liked state.
     */
    public function toggleTracksLike(array $hashes): bool
    {
        $ids = $this->trackIds($hashes);
        if (!$ids) {
            return false;
        }

        $liked = $this->countLikedIds($ids) < count($ids);
        if ($liked) {
            $this->insertLikes($ids);
        } else {
            $this->deleteLikes($ids);
        }

        return $liked;
    }

    /**
     * Resolve track hashes to integer ids, deduped. Unknown hashes are dropped.
     *
     * @return int[]
     */
    private function trackIds(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if (!$hashes) {
            return [];
        }

        $ph   = implode(",", array_fill(0, count($hashes), "?"));
        $rows = db()->fetchAll("SELECT id FROM tracks WHERE hash IN ($ph)", $hashes);

        return array_map(fn($row) => (int) $row['id'], $rows ?: []);
    }

    private function countLikedIds(array $ids): int
    {
        $liked = 0;
        // Chunked so a 2,000-track artist doesn't build a statement with more
        // placeholders than the driver will take.
        foreach (array_chunk($ids, self::LIKE_CHUNK) as $chunk) {
            $ph  = implode(",", array_fill(0, count($chunk), "?"));
            $row = db()->fetch(
                "SELECT COUNT(*) AS liked FROM track_likes
                 WHERE client_id = ? AND track_id IN ($ph)",
                [client()->id, ...$chunk],
            );
            $liked += (int) ($row['liked'] ?? 0);
        }

        return $liked;
    }

    /** Idempotent — the unique(track_id,client_id) index drops the re-likes. */
    private function insertLikes(array $ids): void
    {
        foreach (array_chunk($ids, self::LIKE_CHUNK) as $chunk) {
            $values = implode(",", array_fill(0, count($chunk), "(?,?)"));
            $params = [];
            foreach ($chunk as $id) {
                $params[] = $id;
                $params[] = client()->id;
            }
            db()->execute(
                "INSERT IGNORE INTO track_likes (track_id, client_id) VALUES $values",
                $params,
            );
        }
    }

    private function deleteLikes(array $ids): void
    {
        foreach (array_chunk($ids, self::LIKE_CHUNK) as $chunk) {
            $ph = implode(",", array_fill(0, count($chunk), "?"));
            db()->execute(
                "DELETE FROM track_likes WHERE client_id = ? AND track_id IN ($ph)",
                [client()->id, ...$chunk],
            );
        }
    }

    public function albumTracks(int $albumId): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE t.album_id = ?
             ORDER BY CAST(tm.track_number AS UNSIGNED), tm.track_number",
            [client()->id, $albumId],
        );

        return $this->mapTrackRows($rows);
    }

    public function playlistTracks(int $playlistId): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM playlist_tracks pt
             JOIN tracks t ON t.id = pt.track_id " . self::TRACK_JOINS . "
             WHERE pt.playlist_id = ?
             ORDER BY pt.id ASC",
            [client()->id, $playlistId],
        );

        return $this->mapTrackRows($rows);
    }

    /**
     * Results are ranked by relevance tier — exact track title, then title
     * starts-with/contains, then album, then artist — so the song someone
     * typed lands on top instead of wherever its artist falls alphabetically.
     * Alphabetical order still applies within each tier.
     */
    public function searchTracks(string $term, int $limit = 2500): array
    {
        $like = "%$term%";
        $starts = "$term%";
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE ar.name LIKE ? OR al.title LIKE ? OR tm.title LIKE ?
             ORDER BY CASE
                 WHEN tm.title = ? THEN 0
                 WHEN tm.title LIKE ? THEN 1
                 WHEN tm.title LIKE ? THEN 2
                 WHEN al.title LIKE ? THEN 3
                 WHEN al.title LIKE ? THEN 4
                 WHEN ar.name LIKE ? THEN 5
                 ELSE 6
             END,
             ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $like, $like, $like, $term, $starts, $like, $starts, $like, $starts, $limit],
        );

        return $this->mapTrackRows($rows);
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
                    t.track_artist_id AS artist_id,
                    t.pathname,
                    ar.name AS artist,
                    al.title AS album,
                    tm.title AS title,
                    tm.length_ms AS length_ms,
                    tm.playtime_string AS playtime_string
             FROM tracks t
             JOIN artists ar ON ar.id = t.track_artist_id
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
             AND id NOT IN (SELECT DISTINCT track_artist_id FROM tracks)
             AND id NOT IN (SELECT DISTINCT artist_id FROM albums)"
        );
    }

    /**
     * Own albums plus albums the artist appears on (a track's performer
     * without being the album artist — VA compilations, guest spots). Own
     * albums sort first; appearances carry appears_on for the template.
     */
    public function discography(int $artistId, int $limit = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::ALBUM_COLUMNS . ",
                    (al.artist_id <> ?) AS appears_on
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE al.artist_id = ?
                OR EXISTS (SELECT 1 FROM tracks tx
                           WHERE tx.album_id = al.id AND tx.track_artist_id = ?)
             ORDER BY appears_on ASC, al.year DESC, al.title ASC
             LIMIT ?",
            [$artistId, $artistId, $artistId, $limit],
        );

        return $this->mapTrackRows($rows);
    }

    public function randomTracks($limit = 2500): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             ORDER BY RAND()
             LIMIT ?",
            [client()->id, $limit],
        );

        return $this->mapTrackRows($rows);
    }

    public function likedTracks($limit = 2500): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", 1 AS liked
             FROM tracks t
             JOIN track_likes tl ON tl.track_id = t.id AND tl.client_id=? " . self::TRACK_JOINS . "
             ORDER BY ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $limit],
        );

        return $this->mapTrackRows($rows);
    }

    /**
     * Re-read the liked flag on a set of mapped track rows. Session-cached
     * rows (search results, the queue) snapshot liked at query time, so a
     * like toggled afterwards is lost the next time they're re-rendered.
     *
     * @param array<int,array<string,mixed>> $tracks
     * @return array<int,array<string,mixed>>
     */
    public function refreshLikedState(array $tracks): array
    {
        if (!$tracks) {
            return $tracks;
        }

        $liked = $this->likedHashes(array_column($tracks, 'hash'));
        foreach ($tracks as $i => $track) {
            $tracks[$i]['liked'] = isset($liked[$track['hash'] ?? null]) ? 1 : 0;
        }

        return $tracks;
    }

    /**
     * Which of these track hashes the current client likes right now.
     *
     * @param string[] $hashes
     * @return array<string,true>
     */
    public function likedHashes(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if (!$hashes) {
            return [];
        }

        $placeholders = implode(",", array_fill(0, count($hashes), "?"));
        $rows = db()->fetchAll(
            "SELECT t.hash AS track_hash
             FROM tracks t
             JOIN track_likes tl ON tl.track_id = t.id AND tl.client_id = ?
             WHERE t.hash IN ($placeholders)",
            [client()->id, ...$hashes],
        );

        $liked = [];
        foreach ($rows ?: [] as $row) {
            $liked[$row['track_hash']] = true;
        }

        return $liked;
    }

    public function artistTracks(string $artistHash): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE ar.hash = ?
             GROUP BY t.id
             ORDER BY al.hash, CAST(tm.track_number AS UNSIGNED), tm.track_number",
            [client()->id, $artistHash],
        );

        return $this->mapTrackRows($rows);
    }

    public function topTracksByArtist(int $artistId, int $limit = 10): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ",
                    COUNT(tp.id) AS plays, " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             JOIN track_plays tp ON tp.track_id = t.id
             WHERE t.track_artist_id = ? AND " . self::CLOSED_PLAY . "
             GROUP BY t.id
             ORDER BY plays DESC, tm.title ASC
             LIMIT ?",
            [client()->id, $artistId, $limit],
        );

        return $this->mapTrackRows($rows);
    }

    public function recentlyAdded(int $albumCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::ALBUM_COLUMNS . "
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             ORDER BY al.id DESC
             LIMIT ?",
            [$albumCount],
        );

        return $this->mapTrackRows($rows);
    }

    public function recentlyAddedTracks(int $albumCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             ORDER BY t.id DESC
             LIMIT ?",
            [client()->id, $albumCount],
        );

        return $this->mapTrackRows($rows);
    }

    private function timeAgo(string $datetime): string
    {
        $now  = new \DateTime();
        $past = new \DateTime($datetime);
        $diff = $now->diff($past);

        $intervals = [
            ['year',   $diff->y],
            ['month',  $diff->m],
            ['week',   intdiv($diff->d, 7)],
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

    /**
     * Raw listening history — skips stay visible here, unlike the play-count
     * feeds, since a track you changed away from was still played.
     */
    public function recentlyPlayed(int $trackCount = 1000): array
    {
        $since = (new \DateTime('- 1 WEEK'))->format('Y-m-d H:i:s');

        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ",
                    c.username AS client,
                    MAX(tp.created_at) AS last_played_at, " . self::LIKED_COLUMN . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id " . self::TRACK_JOINS . "
             LEFT JOIN clients c ON c.id = tp.client_id
             WHERE tp.created_at > ?
             GROUP BY t.id
             ORDER BY MAX(tp.id) DESC
             LIMIT ?",
            [client()->id, $since, $trackCount],
        );

        return $this->mapTrackRows($rows);
    }

    public function topPlayed(int $trackCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ",
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id, " . self::LIKED_COLUMN . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id " . self::TRACK_JOINS . "
             WHERE tp.client_id = ? AND " . self::CLOSED_PLAY . "
             GROUP BY t.id
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $trackCount],
        );

        return $this->mapTrackRows($rows);
    }

    public function topPlayedThisMonth(int $trackCount = 50): array
    {
        $since = (new \DateTime('- 1 MONTH'))->format('Y-m-d H:i:s');

        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ",
                    COUNT(tp.id) AS plays,
                    MAX(tp.id) AS last_play_id, " . self::LIKED_COLUMN . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id " . self::TRACK_JOINS . "
             WHERE tp.client_id = ? AND tp.created_at > ? AND " . self::CLOSED_PLAY . "
             GROUP BY t.id
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $since, $trackCount],
        );

        return $this->mapTrackRows($rows);
    }

    /**
     * Tracks with 3+ completed listens that haven't been touched in 30 days.
     * Confirmed skips don't count toward the listen bar, but they do count as
     * "touched" — a track you skipped last week was seen and passed on, not
     * forgotten, so it stays out until the skip ages past the window.
     */
    public function rediscover(int $trackCount = 50): array
    {
        $since = (new \DateTime('- 30 DAY'))->format('Y-m-d H:i:s');

        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ",
                    SUM(CASE WHEN tp.skipped = 1 THEN 0 ELSE 1 END) AS plays,
                    MAX(tp.id) AS last_play_id, " . self::LIKED_COLUMN . "
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id " . self::TRACK_JOINS . "
             WHERE tp.client_id = ?
             GROUP BY t.id
             HAVING SUM(CASE WHEN tp.skipped = 1 THEN 0 ELSE 1 END) >= 3
                AND MAX(tp.created_at) < ?
             ORDER BY plays DESC, last_play_id DESC
             LIMIT ?",
            [client()->id, client()->id, $since, $trackCount],
        );

        return $this->mapTrackRows($rows);
    }

    public function recentlyLiked(int $trackCount = 50): array
    {
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", 1 AS liked
             FROM track_likes tl
             JOIN tracks t ON t.id = tl.track_id " . self::TRACK_JOINS . "
             WHERE tl.client_id = ?
             ORDER BY tl.id DESC
             LIMIT ?",
            [client()->id, $trackCount],
        );

        return $this->mapTrackRows($rows);
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
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE al.genre = ?
             ORDER BY ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $genre, $limit],
        );

        return $this->mapTrackRows($rows);
    }

    public function tracksByDecade(int $decade, int $limit = 2500): array
    {
        $album_title = $decade < 2000 ? substr((string) $decade, 2, 2) : (string) $decade;
        $album_title_s = $album_title . 's';
        $rows = db()->fetchAll(
            "SELECT " . self::TRACK_COLUMNS . ", " . self::LIKED_COLUMN . "
             FROM tracks t " . self::TRACK_JOINS . "
             WHERE (al.year REGEXP '^[0-9]{4}$'
               AND CAST(al.year AS UNSIGNED) BETWEEN ? AND ?
               OR al.title LIKE ? OR al.title LIKE ?)
             ORDER BY al.year ASC, ar.name, al.title, CAST(tm.track_number AS UNSIGNED)
             LIMIT ?",
            [client()->id, $decade, $decade + 9, "%$album_title%", "%$album_title_s%", $limit],
        );

        return $this->mapTrackRows($rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function mapTrackRows(array $rows): array
    {
        return array_map(fn($row) => $this->mapTrackRow($row), $rows);
    }

    public function mapTrackRow(array $row): array
    {
        $entry = [
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

        if (isset($row['plays'])) {
            $entry['plays'] = (int) $row['plays'];
        }
        if (isset($row['appears_on'])) {
            $entry['appears_on'] = (int) $row['appears_on'];
        }
        if (isset($row['last_played_at'])) {
            $entry['ago'] = $this->timeAgo((string) $row['last_played_at']);
        }

        return $entry;
    }
}
