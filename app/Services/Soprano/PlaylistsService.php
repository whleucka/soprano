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
     * Current client's user-created playlists, most-recently-touched first,
     * each with a track_count and a cover (the album art of the first track
     * added, or the default placeholder when empty). Drives the sidebar and
     * the index grid. Generated mixes (slot set) live on the home rail via
     * getGeneratedPlaylists() instead.
     *
     * @return array<int, array{hash: string, name: string, track_count: int, cover: string}>
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

        return array_map(fn($row) => $this->mapPlaylistRow($row), $rows);
    }

    /**
     * The nightly generated mixes for the current client, in a fixed showcase
     * order. Same row shape as getPlaylists(); drives the home "Made For You"
     * rail.
     *
     * @return array<int, array{hash: string, name: string, track_count: int, cover: string}>
     */
    public function getGeneratedPlaylists(): array
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
             WHERE p.client_id = ? AND p.slot IS NOT NULL
             ORDER BY FIELD(p.slot, 'heavy-rotation', 'morning-mix', 'evening-mix',
                            'time-machine', 'rediscover', 'fresh-arrivals'), p.id ASC",
            [client()->id],
        );

        return array_map(fn($row) => $this->mapPlaylistRow($row), $rows);
    }

    /** @return array{hash: string, name: string, track_count: int, cover: string} */
    private function mapPlaylistRow(array $row): array
    {
        return [
            'hash'        => $row['hash'],
            'name'        => $row['name'],
            'track_count' => (int) $row['track_count'],
            'cover'       => $row['cover'] ?: '/images/no-album-art.png',
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
        return Playlist::create([
            'hash'      => bin2hex(random_bytes(16)),
            'client_id' => client()->id,
            'name'      => $name,
        ]);
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
     *
     * @return int[]
     */
    private function trackIds(array $trackHashes): array
    {
        $hashes = array_values(array_unique(array_filter($trackHashes)));
        if (empty($hashes)) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($hashes), '?'));
        $rows = db()->fetchAll("SELECT id FROM tracks WHERE hash IN ($ph)", $hashes);
        return array_map(fn($r) => (int) $r['id'], $rows);
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
