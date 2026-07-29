<?php

namespace App\Services\Soprano;

/**
 * Artist radio: seed from one artist and expand into a personal, on-demand
 * mix — no external API. The seed's own tracks anchor the queue, then it
 * branches out into "related" artists drawn from your own signal:
 *
 *   related artist score = playlist co-occurrence (shared playlists with the
 *                          seed) × {W_PLAYLIST}
 *                        + liked-track count (artists you like) × {W_LIKE}
 *
 * Tracks are then dealt exactly like a Track Station — affinity-scored
 * (like = +3, recency-decayed plays, skips count -2) with a novelty jitter
 * and a per-artist cap so no single neighbour dominates. When a seed has too
 * little co-occurrence signal (few playlists/likes), it falls back to your
 * most-played artists so the radio always fills a full queue.
 *
 * Like Track Stations, mixes are ephemeral: they load straight into the play
 * queue (source: artist-radio:<hash>) and are never saved as playlists.
 */
class MixService
{
    public function __construct(private MusicService $music)
    {
    }

    private const SIZE = 50;

    /** Guaranteed slots for the seed artist before neighbours fill the rest. */
    private const SEED_QUOTA = 15;

    /** Max tracks per neighbouring artist, so the mix stays varied. */
    private const RELATED_CAP = 3;

    /** Weight of the random jitter against the affinity score (likes = 3). */
    private const NOVELTY = 2.0;

    /** Half-life-ish horizon (days) for recency-decayed play scoring. */
    private const DECAY_DAYS = 90.0;

    /** Relation weights: a shared playlist counts for more than a shared like. */
    private const W_PLAYLIST = 3;
    private const W_LIKE      = 1;

    /** How many neighbours to keep, and the floor below which we top up with
     *  most-played artists so a thin-signal seed still gets a full mix. */
    private const MAX_RELATED = 30;
    private const MIN_RELATED = 5;

    /**
     * Deal an artist-radio queue for the session client, in the shared feed
     * row shape. Empty when the artist is unknown or has no tracks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function build(string $artistHash): array
    {
        $seed = db()->fetch("SELECT id FROM artists WHERE hash = ?", [$artistHash]);
        if ($seed === null) {
            return [];
        }
        $seedId = (int) $seed['id'];

        // Anchor: the seed's own tracks, affinity-ordered (no per-artist cap).
        $seedTracks = $this->poolForArtists([$seedId], self::SEED_QUOTA, self::SIZE);
        if (empty($seedTracks)) {
            return [];
        }

        $relatedIds = $this->relatedArtistIds($seedId);
        if (count($relatedIds) < self::MIN_RELATED) {
            $relatedIds = array_values(array_unique(array_merge(
                $relatedIds,
                $this->topPlayedArtistIds($seedId, self::MAX_RELATED),
            )));
        }

        $need = self::SIZE - count($seedTracks);
        $relatedTracks = ($need > 0 && $relatedIds)
            ? $this->poolForArtists($relatedIds, $need, self::RELATED_CAP)
            : [];

        // Seed up front (you tapped this artist), then branch out.
        $queue = array_slice(array_merge($seedTracks, $relatedTracks), 0, self::SIZE);

        return $this->music->mapTrackRows($queue);
    }

    /**
     * Seed artists for the home "Artist Radio" rail: the artists you've been
     * listening to lately, most recent first. Each becomes a tappable card
     * that launches its mix.
     *
     * @return array<int,array<string,mixed>>
     */
    public function homeSeeds(int $limit = 12): array
    {
        return db()->fetchAll(
            "SELECT ar.hash, ar.name, ar.image, MAX(tp.created_at) AS last_played
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE tp.client_id = ? AND " . MusicService::CLOSED_PLAY . "
             GROUP BY ar.id
             ORDER BY last_played DESC
             LIMIT " . (int) $limit,
            [client()->id],
        );
    }

    /**
     * Related-artist ids for the seed, scored by playlist co-occurrence (both
     * seed and neighbour in the same client playlist) plus liked-track count,
     * highest first. Excludes the seed itself.
     *
     * @return array<int,int>
     */
    private function relatedArtistIds(int $seedId): array
    {
        $cid = client()->id;

        // Neighbours that share a playlist with the seed.
        $playlist = db()->fetchAll(
            "SELECT t2.track_artist_id AS artist_id,
                    COUNT(DISTINCT pt1.playlist_id) AS w
             FROM playlist_tracks pt1
             JOIN playlists p ON p.id = pt1.playlist_id AND p.client_id = ?
             JOIN tracks t1 ON t1.id = pt1.track_id AND t1.track_artist_id = ?
             JOIN playlist_tracks pt2 ON pt2.playlist_id = pt1.playlist_id
             JOIN tracks t2 ON t2.id = pt2.track_id
             WHERE t2.track_artist_id <> ?
             GROUP BY t2.track_artist_id",
            [$cid, $seedId, $seedId],
        );

        // Artists you like, as a general affinity boost.
        $liked = db()->fetchAll(
            "SELECT t.track_artist_id AS artist_id, COUNT(*) AS w
             FROM track_likes tl
             JOIN tracks t ON t.id = tl.track_id
             WHERE tl.client_id = ? AND t.track_artist_id <> ?
             GROUP BY t.track_artist_id",
            [$cid, $seedId],
        );

        $scores = [];
        foreach ($playlist as $r) {
            $id = (int) $r['artist_id'];
            $scores[$id] = ($scores[$id] ?? 0) + (int) $r['w'] * self::W_PLAYLIST;
        }
        foreach ($liked as $r) {
            $id = (int) $r['artist_id'];
            $scores[$id] = ($scores[$id] ?? 0) + (int) $r['w'] * self::W_LIKE;
        }
        arsort($scores);

        return array_slice(array_keys($scores), 0, self::MAX_RELATED);
    }

    /**
     * Fallback neighbours when co-occurrence is thin: your most-played
     * artists (skips excluded), excluding the seed.
     *
     * @return array<int,int>
     */
    private function topPlayedArtistIds(int $seedId, int $limit): array
    {
        $rows = db()->fetchAll(
            "SELECT t.track_artist_id AS artist_id, COUNT(tp.id) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             WHERE tp.client_id = ? AND t.track_artist_id <> ? AND " . MusicService::CLOSED_PLAY . "
             GROUP BY t.track_artist_id
             ORDER BY plays DESC
             LIMIT " . (int) $limit,
            [client()->id, $seedId],
        );

        return array_map(static fn ($r) => (int) $r['artist_id'], $rows);
    }

    /**
     * Deal up to $limit tracks from the given artist ids, affinity-scored with
     * a novelty jitter and a per-artist cap. Same shape/scoring as a Track
     * Station deal.
     *
     * @param array<int,int> $artistIds
     * @return array<int,array<string,mixed>>
     */
    private function poolForArtists(array $artistIds, int $limit, int $perArtistCap): array
    {
        if (empty($artistIds)) {
            return [];
        }
        $cid = client()->id;
        $in  = implode(',', array_fill(0, count($artistIds), '?'));

        $rows = db()->fetchAll(
            "SELECT " . MusicService::TRACK_COLUMNS . ", " . MusicService::LIKED_COLUMN . "
             FROM tracks t " . MusicService::TRACK_JOINS . "
             LEFT JOIN (
                 SELECT track_id,
                        SUM((CASE WHEN skipped = 1 THEN -2 ELSE 1 END)
                            * EXP(-DATEDIFF(NOW(), created_at) / ?)) AS play_score
                 FROM track_plays
                 WHERE client_id = ?
                 GROUP BY track_id
             ) ps ON ps.track_id = t.id
             LEFT JOIN track_likes tl2 ON tl2.track_id = t.id AND tl2.client_id = ?
             WHERE t.track_artist_id IN ($in)
             GROUP BY t.id
             ORDER BY (IFNULL(ps.play_score, 0)
                       + IF(tl2.id IS NULL, 0, 3)
                       + RAND() * ?) DESC
             LIMIT " . (self::SIZE * 3),
            array_merge([$cid, self::DECAY_DAYS, $cid, $cid], $artistIds, [self::NOVELTY]),
        );

        $out = [];
        $per = [];
        foreach ($rows as $row) {
            $artist = $row['artist_hash'];
            if (($per[$artist] ?? 0) >= $perArtistCap) {
                continue;
            }
            $per[$artist] = ($per[$artist] ?? 0) + 1;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
