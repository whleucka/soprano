<?php

namespace App\Services\Soprano;

use App\Models\Playlist;

/**
 * Persistent, per-client playlists (distinct from PlaylistService, which is the
 * ephemeral now-playing queue). Reads use db() for the list/aggregate queries
 * since the ORM has no JOIN; writes go through the models or raw INSERT IGNORE.
 *
 * Membership operates on a *selection* — a set of track hashes resolved by the
 * controller from a track, album, artist or search. A single track is just a
 * one-element selection.
 */
class PlaylistsService
{
    /**
     * Reserved hashes for the virtual playlists. Neither has a `playlists`
     * row — "Liked" is track_likes and "Random" is a seeded shuffle of the
     * library, both rendered through the playlist views — so every playlist
     * route special-cases them before hitting the database.
     */
    public const LIKED_HASH = 'liked';
    public const RANDOM_HASH = 'random';

    /** How many tracks the virtual "Random" playlist deals out. */
    public const RANDOM_SIZE = 2500;

    /**
     * Current client's user-created playlists, most-recently-touched first,
     * each with a track_count and a cover (the album art of the first track
     * added, or the default placeholder when empty). Led by the two virtual
     * playlists, which carry an icon instead of cover art. Drives the sidebar
     * and the index grid. Generated mixes (slot set) live on the home rail via
     * getGeneratedPlaylists() instead.
     *
     * @return array<int, array{hash: string, name: string, track_count: int, cover: string, icon: ?string}>
     */
    public function getPlaylists(): array
    {
        $rows = db()->fetchAll(
            "SELECT p.hash AS hash,
                    p.name AS name,
                    (SELECT COUNT(*) FROM playlist_tracks pt WHERE pt.playlist_id = p.id) AS track_count,
                    (SELECT al.cover
                       FROM playlist_tracks pt
                       JOIN tracks t ON t.id = pt.track_id
                       JOIN albums al ON al.id = t.album_id
                       WHERE pt.playlist_id = p.id
                       ORDER BY pt.id ASC
                       LIMIT 1) AS cover
             FROM playlists p
             WHERE p.client_id = ? AND p.slot IS NULL
             ORDER BY p.updated_at DESC, p.id DESC",
            [client()->id],
        );

        return [
            $this->likedPlaylist(),
            $this->randomPlaylist(),
            ...array_map(fn($row) => $this->mapPlaylistRow($row), $rows),
        ];
    }

    /**
     * The virtual "Liked" playlist row, shaped like any other playlist so the
     * sidebar and the index grid can render it without knowing it's special.
     * Wears a filled heart rather than album art.
     *
     * @return array{hash: string, name: string, slot: ?string, track_count: int, cover: string, icon: ?string}
     */
    public function likedPlaylist(): array
    {
        $row = db()->fetch(
            "SELECT COUNT(*) AS track_count FROM track_likes WHERE client_id = ?",
            [client()->id],
        );

        return $this->mapPlaylistRow([
            'hash'        => self::LIKED_HASH,
            'name'        => 'Liked',
            'track_count' => $row['track_count'] ?? 0,
            'icon'        => 'bi-heart-fill',
        ]);
    }

    /**
     * The virtual "Random" playlist row — a dice-faced shuffle of the whole
     * library, capped at RANDOM_SIZE tracks (what the playlist actually
     * deals out, so the count matches the rendered rows).
     *
     * @return array{hash: string, name: string, slot: ?string, track_count: int, cover: string, icon: ?string}
     */
    public function randomPlaylist(): array
    {
        $row = db()->fetch("SELECT COUNT(*) AS track_count FROM tracks");

        return $this->mapPlaylistRow([
            'hash'        => self::RANDOM_HASH,
            'name'        => 'Random',
            'track_count' => min((int) ($row['track_count'] ?? 0), self::RANDOM_SIZE),
            'icon'        => 'bi-dice-6-fill',
        ]);
    }

    /**
     * Ordering seed for the virtual "Random" playlist. The page, its track
     * list and the click that plays row N are three separate requests — a
     * fresh RAND() in each would play a different track than the one clicked,
     * so the shuffle is pinned to a session seed.
     */
    public function randomSeed(): int
    {
        $seed = state()->random['seed'] ?? null;

        return $seed === null ? $this->rerollRandomSeed() : (int) $seed;
    }

    /** Deal a new hand. Called when the Random playlist page is opened. */
    public function rerollRandomSeed(): int
    {
        $seed = random_int(1, 2147483647);
        state()->random = ['seed' => $seed];

        return $seed;
    }

    /**
     * The nightly generated mixes for the current client, in a fixed showcase
     * order. Same row shape as getPlaylists(); drives the home "Made For You"
     * rail.
     *
     * @return array<int, array{hash: string, name: string, slot: ?string, track_count: int, cover: string, icon: ?string}>
     */
    public function getGeneratedPlaylists(): array
    {
        $rows = db()->fetchAll(
            "SELECT p.hash AS hash,
                    p.name AS name,
                    p.slot AS slot,
                    (SELECT COUNT(*) FROM playlist_tracks pt WHERE pt.playlist_id = p.id) AS track_count,
                    (SELECT al.cover
                       FROM playlist_tracks pt
                       JOIN tracks t ON t.id = pt.track_id
                       JOIN albums al ON al.id = t.album_id
                       WHERE pt.playlist_id = p.id
                       ORDER BY pt.id ASC
                       LIMIT 1) AS cover
             FROM playlists p
             WHERE p.client_id = ? AND p.slot IS NOT NULL
             ORDER BY FIELD(p.slot, 'heavy-rotation', 'morning-mix', 'evening-mix',
                            'time-machine', 'rediscover', 'fresh-arrivals'), p.id ASC",
            [client()->id],
        );

        return array_map(fn($row) => $this->mapPlaylistRow($row), $rows);
    }

    /**
     * @return array{hash: string, name: string, slot: ?string, track_count: int, cover: string, icon: ?string}
     */
    private function mapPlaylistRow(array $row): array
    {
        return [
            'hash'        => $row['hash'],
            'name'        => $row['name'],
            'slot'        => $row['slot'] ?? null,
            'track_count' => (int) $row['track_count'],
            // An icon replaces the cover art entirely (virtual playlists).
            'icon'        => $row['icon'] ?? null,
            'cover'       => ($row['cover'] ?? null) ?: '/images/no-album-art.png',
        ];
    }

    public function getPlaylistByHash(string $hash): ?Playlist
    {
        return Playlist::where('hash', $hash)
            ->andWhere('client_id', client()->id)
            ->first();
    }

    public function createPlaylist(string $name): Playlist|bool
    {
        $name = $this->cleanName($name);
        if ($name === '') {
            return false;
        }

        return Playlist::create([
            'hash'      => bin2hex(random_bytes(16)),
            'client_id' => client()->id,
            'name'      => $name,
        ]);
    }

    /**
     * Rename one of the current client's playlists. Returns the stored name,
     * or null when the hash isn't theirs or the name is blank once trimmed.
     *
     * The row's updated_at is ON UPDATE CURRENT_TIMESTAMP, so a rename also
     * floats the playlist to the top of getPlaylists()' most-recently-touched
     * ordering. That is the same thing adding a track does.
     */
    public function renamePlaylist(string $hash, string $name): ?string
    {
        $playlist = $this->getPlaylistByHash($hash);
        $name     = $this->cleanName($name);
        if (!$playlist || $name === '') {
            return null;
        }

        $playlist->update(['name' => $name]);

        return $name;
    }

    /**
     * A playlist name is free text and stays that way — emoji included. The
     * column is utf8mb4 VARCHAR(255) and every render site escapes, so the
     * only thing worth doing here is dropping surrounding whitespace, which
     * is invisible in the sidebar and makes two names look identical.
     *
     * trim() only strips ASCII bytes, none of which can appear inside a
     * multi-byte UTF-8 sequence, so it cannot cut an emoji in half.
     */
    private function cleanName(string $name): string
    {
        return trim($name);
    }

    public function deletePlaylist(string $hash): void
    {
        $this->getPlaylistByHash($hash)?->delete();
    }

    /**
     * Add every track in the selection to the playlist. Idempotent — the
     * unique(playlist_id,track_id) constraint drops tracks already present.
     */
    public function addSelection(string $hash, array $trackHashes): void
    {
        $playlist = $this->getPlaylistByHash($hash);
        $ids      = $this->trackIds($trackHashes);
        if (!$playlist || empty($ids)) {
            return;
        }
        $this->insertIds((int) $playlist->id, $ids);
        $playlist->touch();
    }

    public function removeSelection(string $hash, array $trackHashes): void
    {
        $playlist = $this->getPlaylistByHash($hash);
        $ids      = $this->trackIds($trackHashes);
        if (!$playlist || empty($ids)) {
            return;
        }
        $this->deleteIds((int) $playlist->id, $ids);
        $playlist->touch();
    }

    /**
     * If the playlist already contains the whole selection, remove it; otherwise
     * add it. Gives the modal a consistent click-to-add / click-again-to-undo.
     */
    public function toggleSelection(string $hash, array $trackHashes): void
    {
        $playlist = $this->getPlaylistByHash($hash);
        $ids      = $this->trackIds($trackHashes);
        if (!$playlist || empty($ids)) {
            return;
        }
        if ($this->countPresent((int) $playlist->id, $ids) >= count($ids)) {
            $this->deleteIds((int) $playlist->id, $ids);
        } else {
            $this->insertIds((int) $playlist->id, $ids);
        }
        $playlist->touch();
    }

    /** Remove a single track (used by the playlist view's row menu). */
    public function removeTrack(string $hash, string $trackHash): void
    {
        $this->removeSelection($hash, [$trackHash]);
    }

    /**
     * Every playlist for the current client, each with a `contains` flag that is
     * true only when the playlist already holds the *entire* selection. Drives
     * the modal's add/added indicator. With an empty selection, `contains` is
     * false for all (used by the sidebar "New playlist" entry).
     *
     * @return array<int, array{hash: string, name: string, contains: bool}>
     */
    public function playlistsForSelection(array $trackHashes): array
    {
        $ids = $this->trackIds($trackHashes);

        // Generated mixes are excluded — the nightly job wipes their contents,
        // so hand-adding tracks to them would be a trap.
        if (empty($ids)) {
            $rows = db()->fetchAll(
                "SELECT hash, name FROM playlists WHERE client_id = ? AND slot IS NULL ORDER BY name ASC",
                [client()->id],
            );
            return array_map(fn($r) => [
                'hash' => $r['hash'], 'name' => $r['name'], 'contains' => false,
            ], $rows);
        }

        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $total = count($ids);
        $rows  = db()->fetchAll(
            "SELECT p.hash AS hash,
                    p.name AS name,
                    COUNT(pt.track_id) AS present
             FROM playlists p
             LEFT JOIN playlist_tracks pt
               ON pt.playlist_id = p.id AND pt.track_id IN ($ph)
             WHERE p.client_id = ? AND p.slot IS NULL
             GROUP BY p.id
             ORDER BY p.name ASC",
            [...$ids, client()->id],
        );

        return array_map(fn($r) => [
            'hash'     => $r['hash'],
            'name'     => $r['name'],
            'contains' => (int) $r['present'] >= $total,
        ], $rows);
    }

    /**
     * Resolve track hashes to integer ids, deduped. Unknown hashes are dropped.
     * Selection order is preserved — playlist_tracks has no position column, so
     * insertion order (pt.id) IS the playlist order.
     *
     * @return int[]
     */
    private function trackIds(array $trackHashes): array
    {
        $hashes = array_values(array_unique(array_filter($trackHashes)));
        if (empty($hashes)) {
            return [];
        }
        $ph     = implode(',', array_fill(0, count($hashes), '?'));
        $rows   = db()->fetchAll("SELECT id, hash FROM tracks WHERE hash IN ($ph)", $hashes);
        $byHash = array_column($rows, 'id', 'hash');

        $ids = [];
        foreach ($hashes as $hash) {
            if (isset($byHash[$hash])) {
                $ids[] = (int) $byHash[$hash];
            }
        }
        return $ids;
    }

    private function insertIds(int $playlistId, array $ids): void
    {
        $values = implode(',', array_fill(0, count($ids), '(?,?)'));
        $params = [];
        foreach ($ids as $id) {
            $params[] = $playlistId;
            $params[] = $id;
        }
        db()->execute(
            "INSERT IGNORE INTO playlist_tracks (playlist_id, track_id) VALUES $values",
            $params,
        );
    }

    private function deleteIds(int $playlistId, array $ids): void
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        db()->execute(
            "DELETE FROM playlist_tracks WHERE playlist_id = ? AND track_id IN ($ph)",
            [$playlistId, ...$ids],
        );
    }

    private function countPresent(int $playlistId, array $ids): int
    {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $row = db()->fetch(
            "SELECT COUNT(*) AS c FROM playlist_tracks WHERE playlist_id = ? AND track_id IN ($ph)",
            [$playlistId, ...$ids],
        );
        return (int) ($row['c'] ?? 0);
    }
}
