<?php

namespace App\Services\Soprano;

use App\Models\Playlist;

/**
 * Nightly, per-client generated playlists built from listening history. Each
 * generator owns a slot ('heavy-rotation', 'morning-mix', …) and a client has
 * at most one playlist per slot, keyed by playlists.slot. Regeneration swaps
 * the tracks in place so the hash (and anything linking to it) stays stable,
 * like a daily mix. Slots without enough signal are removed, not published.
 *
 * Runs from the scheduler with no session, so unlike the other Soprano
 * services nothing here may use client() — every query takes an explicit
 * client id. Skip-aware queries treat NULL as not-skipped since rows from
 * before skip tracking (and unreported plays) carry NULL.
 *
 * Every generator applies MusicService::LONG_ENOUGH, so silence tracks and
 * short interludes never land in a mix — same gate the stations and artist
 * radio use.
 */
class AutoPlaylistService
{
    private const PLAYLIST_SIZE = 100;

    /** Slots with fewer tracks than this are removed rather than published. */
    private const MIN_TRACKS = 5;

    /** Hour bands (inclusive, server time) for the time-of-day mixes. */
    private const MORNING = [5, 11];
    private const EVENING = [17, 23];

    /** Minimum plays inside an hour band before a time-of-day mix exists. */
    private const MIN_BAND_PLAYS = 25;

    private const NOT_SKIPPED = "(tp.skipped IS NULL OR tp.skipped = 0)";

    /**
     * Clients with any listening history — the ones worth generating for.
     *
     * @return int[]
     */
    public function activeClientIds(): array
    {
        $rows = db()->fetchAll(
            "SELECT DISTINCT client_id FROM track_plays WHERE client_id IS NOT NULL"
        );

        return array_map(fn($r) => (int) $r['client_id'], $rows);
    }

    /**
     * (Re)generate every slot for one client.
     *
     * @return array<string,int> slot => published track count (0 = removed/skipped)
     */
    public function generateForClient(int $clientId): array
    {
        $slots = [
            'heavy-rotation' => ['Heavy Rotation', $this->heavyRotation($clientId)],
            'rediscover'     => ['Rediscover', $this->rediscover($clientId)],
            'fresh-arrivals' => ['Fresh Arrivals', $this->freshArrivals($clientId)],
            'time-machine'   => ['Time Machine', $this->timeMachine($clientId)],
            'morning-mix'    => ['Morning Mix', $this->hourBandMix($clientId, ...self::MORNING)],
            'evening-mix'    => ['Evening Mix', $this->hourBandMix($clientId, ...self::EVENING)],
        ];

        $result = [];
        foreach ($slots as $slot => [$name, $trackIds]) {
            $result[$slot] = $this->publish($clientId, $slot, $name, $trackIds);
        }

        return $result;
    }

    /**
     * Most played over the last 30 days, confirmed skips excluded.
     *
     * @return int[]
     */
    private function heavyRotation(int $clientId): array
    {
        return $this->ids(db()->fetchAll(
            "SELECT t.id
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             WHERE tp.client_id = ?
               AND tp.created_at > NOW() - INTERVAL 30 DAY
               AND " . self::NOT_SKIPPED . "
               AND " . MusicService::LONG_ENOUGH . "
             GROUP BY t.id
             ORDER BY COUNT(*) DESC, MAX(tp.id) DESC
             LIMIT ?",
            [$clientId, self::PLAYLIST_SIZE],
        ));
    }

    /**
     * Liked or once-heavy (3+ lifetime plays) tracks not played in 6+ months —
     * including liked tracks never played at all. Liked first, then by how
     * heavy they once were.
     *
     * @return int[]
     */
    private function rediscover(int $clientId): array
    {
        return $this->ids(db()->fetchAll(
            "SELECT t.id
             FROM tracks t
             LEFT JOIN (SELECT track_id, COUNT(*) AS plays, MAX(created_at) AS last_played
                        FROM track_plays
                        WHERE client_id = ?
                        GROUP BY track_id) p ON p.track_id = t.id
             LEFT JOIN track_likes tl ON tl.track_id = t.id AND tl.client_id = ?
             WHERE (tl.id IS NOT NULL OR COALESCE(p.plays, 0) >= 3)
               AND (p.last_played IS NULL OR p.last_played < NOW() - INTERVAL 6 MONTH)
               AND " . MusicService::LONG_ENOUGH . "
             ORDER BY (tl.id IS NOT NULL) DESC, COALESCE(p.plays, 0) DESC, RAND()
             LIMIT ?",
            [$clientId, $clientId, self::PLAYLIST_SIZE],
        ));
    }

    /**
     * Recently synced tracks this client has barely touched, newest first.
     *
     * @return int[]
     */
    private function freshArrivals(int $clientId): array
    {
        return $this->ids(db()->fetchAll(
            "SELECT t.id
             FROM tracks t
             LEFT JOIN track_plays tp ON tp.track_id = t.id AND tp.client_id = ?
             WHERE t.created_at > NOW() - INTERVAL 30 DAY
               AND " . MusicService::LONG_ENOUGH . "
             GROUP BY t.id
             HAVING COUNT(tp.id) <= 1
             ORDER BY t.id DESC
             LIMIT ?",
            [$clientId, self::PLAYLIST_SIZE],
        ));
    }

    /**
     * A decade mix weighted by which decades the client actually plays: each
     * decade gets a share of the playlist proportional to its share of plays,
     * filled with random tracks from that decade and shuffled together.
     *
     * @return int[]
     */
    private function timeMachine(int $clientId): array
    {
        $decades = db()->fetchAll(
            "SELECT (CAST(al.year AS UNSIGNED) DIV 10) * 10 AS decade, COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             WHERE tp.client_id = ? AND al.year REGEXP '^[0-9]{4}$'
             GROUP BY decade
             ORDER BY plays DESC",
            [$clientId],
        );

        $total = array_sum(array_map(fn($r) => (int) $r['plays'], $decades));
        if ($total === 0) {
            return [];
        }

        $trackIds = [];
        foreach ($decades as $row) {
            $take = (int) round(self::PLAYLIST_SIZE * (int) $row['plays'] / $total);
            if ($take < 1) {
                continue;
            }
            $decade = (int) $row['decade'];
            $trackIds = array_merge($trackIds, $this->ids(db()->fetchAll(
                "SELECT t.id
                 FROM tracks t
                 JOIN albums al ON al.id = t.album_id
                 WHERE al.year REGEXP '^[0-9]{4}$'
                   AND CAST(al.year AS UNSIGNED) BETWEEN ? AND ?
                   AND " . MusicService::LONG_ENOUGH . "
                 ORDER BY RAND()
                 LIMIT ?",
                [$decade, $decade + 9, $take],
            )));
        }

        shuffle($trackIds);

        return array_slice($trackIds, 0, self::PLAYLIST_SIZE);
    }

    /**
     * Time-of-day mix: the artists and genres the client historically plays
     * inside the hour band, most-played-in-band tracks first, then random
     * picks from those artists/genres as discovery filler. Skipped only when
     * the band has too little history to say anything.
     *
     * @return int[]
     */
    private function hourBandMix(int $clientId, int $fromHour, int $toHour): array
    {
        $band = "HOUR(tp.created_at) BETWEEN ? AND ?";

        $row = db()->fetch(
            "SELECT COUNT(*) AS plays FROM track_plays tp
             WHERE tp.client_id = ? AND $band",
            [$clientId, $fromHour, $toHour],
        );
        if ((int) ($row['plays'] ?? 0) < self::MIN_BAND_PLAYS) {
            return [];
        }

        $artistIds = $this->ids(db()->fetchAll(
            "SELECT t.track_artist_id AS id
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             WHERE tp.client_id = ? AND $band AND " . self::NOT_SKIPPED . "
             GROUP BY t.track_artist_id
             ORDER BY COUNT(*) DESC
             LIMIT 15",
            [$clientId, $fromHour, $toHour],
        ));

        $genres = array_map(fn($r) => $r['genre'], db()->fetchAll(
            "SELECT al.genre AS genre
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             WHERE tp.client_id = ? AND $band
               AND al.genre <> '' AND al.genre <> 'Unknown'
             GROUP BY al.genre
             ORDER BY COUNT(*) DESC
             LIMIT 5",
            [$clientId, $fromHour, $toHour],
        ));

        if (empty($artistIds) && empty($genres)) {
            return [];
        }

        $where  = [];
        $params = [$clientId, $fromHour, $toHour];
        if (!empty($artistIds)) {
            $where[]  = "t.track_artist_id IN (" . implode(',', array_fill(0, count($artistIds), '?')) . ")";
            $params   = array_merge($params, $artistIds);
        }
        if (!empty($genres)) {
            $where[]  = "al.genre IN (" . implode(',', array_fill(0, count($genres), '?')) . ")";
            $params   = array_merge($params, $genres);
        }
        $params[] = self::PLAYLIST_SIZE;

        return $this->ids(db()->fetchAll(
            "SELECT t.id
             FROM tracks t
             JOIN albums al ON al.id = t.album_id
             LEFT JOIN (SELECT track_id, COUNT(*) AS plays
                        FROM track_plays tp
                        WHERE tp.client_id = ? AND $band AND " . self::NOT_SKIPPED . "
                        GROUP BY track_id) bp ON bp.track_id = t.id
             WHERE (" . implode(' OR ', $where) . ")
               AND " . MusicService::LONG_ENOUGH . "
             ORDER BY COALESCE(bp.plays, 0) DESC, RAND()
             LIMIT ?",
            $params,
        ));
    }

    /**
     * Upsert the slot's playlist and swap its tracks in place. A multi-row
     * insert assigns playlist_tracks ids in VALUES order, which is the play
     * order (rows are read ORDER BY id ASC).
     */
    private function publish(int $clientId, string $slot, string $name, array $trackIds): int
    {
        $trackIds = array_values(array_unique($trackIds));

        /** @var ?Playlist $playlist */
        $playlist = Playlist::where('client_id', $clientId)
            ->andWhere('slot', $slot)
            ->first();

        if (count($trackIds) < self::MIN_TRACKS) {
            // Not enough signal — retire the slot instead of publishing a stub.
            $playlist?->delete();
            return 0;
        }

        if (!$playlist) {
            $playlist = Playlist::create([
                'hash'      => bin2hex(random_bytes(16)),
                'client_id' => $clientId,
                'name'      => $name,
                'slot'      => $slot,
            ]);
            if (!$playlist instanceof Playlist) {
                return 0;
            }
        }

        $playlistId = (int) $playlist->id;
        db()->execute("DELETE FROM playlist_tracks WHERE playlist_id = ?", [$playlistId]);

        $values = implode(',', array_fill(0, count($trackIds), '(?,?)'));
        $params = [];
        foreach ($trackIds as $id) {
            $params[] = $playlistId;
            $params[] = $id;
        }
        db()->execute(
            "INSERT IGNORE INTO playlist_tracks (playlist_id, track_id) VALUES $values",
            $params,
        );
        db()->execute("UPDATE playlists SET updated_at = NOW() WHERE id = ?", [$playlistId]);

        return count($trackIds);
    }

    /**
     * Pluck integer ids out of fetched rows.
     *
     * @return int[]
     */
    private function ids(array $rows): array
    {
        return array_map(fn($r) => (int) $r['id'], $rows);
    }
}
