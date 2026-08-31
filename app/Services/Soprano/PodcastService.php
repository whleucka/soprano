<?php

namespace App\Services\Soprano;

use App\Models\{Podcast, PodcastLike, PodcastProgress};

/**
 * Podcast business logic: search, the per-client liked library, podcast/episode
 * detail, and the aggregated "latest episodes" home feed.
 *
 * The ListenNotes catalog lives remotely; locally we persist only a *snapshot*
 * of podcasts a client has liked (created on first like) plus the likes join
 * table. Mirrors RadioService's shape so the controllers/templates feel the same.
 */
class PodcastService
{
    public function __construct(private ListenNotesService $api) {}

    /**
     * Paginated podcast search. Returns a view-model ready for the results
     * partial; `next_offset` is null when there are no further pages.
     *
     * @return array{items: array, total: int, next_offset: ?int, query: string}
     */
    public function search(string $query, int $offset = 0): array
    {
        $empty = ['items' => [], 'total' => 0, 'next_offset' => null, 'query' => $query];

        $data = $this->api->search($query, $offset);
        if (!$data || empty($data['results'])) {
            return $empty;
        }

        $items = $this->markLiked(array_map(fn($r) => $this->mapPodcast($r), $data['results']));

        $total = (int) ($data['total'] ?? count($items));
        $next  = $data['next_offset'] ?? null;
        // Stop paginating once we've exhausted the result set.
        if ($next !== null && $next >= $total) {
            $next = null;
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'next_offset' => $next,
            'query'       => $query,
        ];
    }

    /**
     * Curated "best podcasts" discovery listing, optionally scoped to a genre.
     * Page-based pagination (`next_page` is null on the last page).
     *
     * @return array{items: array, next_page: ?int, genre_name: ?string}
     */
    public function best(?string $genreId = null, int $page = 1): array
    {
        $data = $this->api->getBestPodcasts($page, $genreId);
        if (!$data || empty($data['podcasts'])) {
            return ['items' => [], 'next_page' => null, 'genre_name' => null];
        }

        $items = $this->markLiked(array_map(fn($r) => $this->mapPodcast($r), $data['podcasts']));
        $next  = (!empty($data['has_next']) && !empty($data['next_page_number']))
            ? (int) $data['next_page_number']
            : null;

        return [
            'items'      => $items,
            'next_page'  => $next,
            // `name` is the genre name when scoped, or "Podcasts" overall.
            'genre_name' => $genreId !== null ? ($data['name'] ?? null) : null,
        ];
    }

    /**
     * Top-level genres for the discovery filter control.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function genres(): array
    {
        $data = $this->api->getGenres(true);
        return $data['genres'] ?? [];
    }

    /**
     * Podcast detail + a page of episodes (newest first).
     */
    public function getPodcast(string $hash, ?int $nextEpisodePubDate = null): ?array
    {
        $data = $this->api->getPodcast($hash, $nextEpisodePubDate);
        if (!$data || empty($data['id'])) {
            return null;
        }

        $podcast = $this->mapPodcast($data);
        $podcast['liked'] = $this->isLiked($hash);
        $podcast['episodes'] = array_map(
            fn($e) => $this->mapEpisode($e, $podcast),
            $data['episodes'] ?? [],
        );
        $podcast['next_episode_pub_date'] = $data['next_episode_pub_date'] ?? null;
        $podcast['episodes'] = $this->markProgress($podcast['episodes']);

        return $podcast;
    }

    /**
     * Resolve a single episode (for playback). Goes straight to the episode
     * endpoint so it works regardless of which page the episode came from.
     */
    public function getEpisode(string $episodeId): ?array
    {
        return $this->episodeFromResponse($this->api->getEpisode($episodeId));
    }

    /**
     * A random episode for the "Surprise me" button (ListenNotes /just_listen).
     */
    public function randomEpisode(): ?array
    {
        return $this->episodeFromResponse($this->api->justListen());
    }

    /**
     * Map a ListenNotes episode response (which carries a nested `podcast`
     * node) into a playable episode view-model. Shared by getEpisode/random.
     */
    private function episodeFromResponse(?array $data): ?array
    {
        if (!$data || empty($data['id'])) {
            return null;
        }

        $node = $data['podcast'] ?? [];
        $podcast = [
            'hash'      => $node['id'] ?? '',
            'title'     => $node['title_original'] ?? $node['title'] ?? '',
            'thumbnail' => $node['thumbnail'] ?? $node['image'] ?? '/images/no-album-art.png',
        ];

        return $this->mapEpisode($data, $podcast);
    }

    /**
     * The client's liked podcasts (snapshot rows), newest like first.
     */
    public function getLikedPodcasts(): array
    {
        $rows = db()->fetchAll(
            "SELECT p.hash, p.title, p.publisher, p.image, p.thumbnail, p.total_episodes
             FROM podcasts p
             JOIN podcast_likes pl ON pl.podcast_id = p.id
             WHERE pl.client_id = ?
             ORDER BY pl.created_at DESC",
            [client()->id],
        );

        return array_map(fn($row) => [
            'hash'           => $row['hash'],
            'title'          => $row['title'],
            'publisher'      => $row['publisher'] ?? '',
            'image'          => $row['image'] ?: '/images/no-album-art.png',
            'thumbnail'      => $row['thumbnail'] ?: ($row['image'] ?: '/images/no-album-art.png'),
            'total_episodes' => (int) ($row['total_episodes'] ?? 0),
            'liked'          => true,
        ], $rows);
    }

    public function isLiked(string $hash): bool
    {
        $podcast = Podcast::where('hash', $hash)->first();
        if (!$podcast) {
            return false;
        }
        $like = PodcastLike::where('podcast_id', $podcast->id)
            ->andWhere('client_id', client()->id)->first();

        return (bool) $like;
    }

    /**
     * Toggle a like. On first like we materialise a snapshot row from the API
     * (search results are never stored), mirroring RadioService::toggleStationLike.
     */
    public function toggleLike(string $hash): void
    {
        $podcast = Podcast::where('hash', $hash)->first();

        if (!$podcast) {
            $data = $this->getPodcast($hash);
            if (!$data) {
                return;
            }
            $podcast = Podcast::create([
                'hash'            => $data['hash'],
                'title'           => $data['title'],
                'publisher'       => $data['publisher'] ?: null,
                'description'     => $data['description'] ?: null,
                'image'           => $data['image'] ?: null,
                'thumbnail'       => $data['thumbnail'] ?: null,
                'total_episodes'  => $data['total_episodes'] ?: null,
                'listennotes_url' => $data['listennotes_url'] ?? null,
            ]);
            if (!$podcast) {
                return;
            }
        }

        $like = PodcastLike::where('podcast_id', $podcast->id)
            ->andWhere('client_id', client()->id)->first();

        if ($like) {
            $like->delete();
        } else {
            PodcastLike::create([
                'podcast_id' => $podcast->id,
                'client_id'  => client()->id,
            ]);
        }
    }

    /**
     * Upsert the resume row for an episode at play time (the only moment we
     * hold full episode metadata) and return the position to resume from.
     * A nearly-finished episode restarts from the top instead of resuming
     * into the outro.
     */
    public function touchProgress(array $episode): int
    {
        $row = PodcastProgress::where('episode_id', $episode['id'])
            ->andWhere('client_id', client()->id)->first();

        if (!$row) {
            PodcastProgress::create([
                'client_id'     => client()->id,
                'episode_id'    => $episode['id'],
                'podcast_hash'  => $episode['podcast_hash'],
                'podcast_title' => $episode['podcast_title'],
                'episode_title' => $episode['title'],
                'image'         => $episode['image'] ?: null,
                'duration_sec'  => $episode['audio_length_sec'] ?: null,
                'position_ms'   => 0,
            ]);
            return 0;
        }

        $pos = (int) $row->position_ms;
        if ($this->isFinished($pos, (int) $row->duration_sec)) {
            $pos = 0;
        }
        // Refresh metadata (title/artwork can change upstream) and bump
        // updated_at so this episode floats to the top of Continue Listening.
        $row->update([
            'podcast_title' => $episode['podcast_title'],
            'episode_title' => $episode['title'],
            'image'         => $episode['image'] ?: null,
            'duration_sec'  => $episode['audio_length_sec'] ?: null,
            'position_ms'   => $pos,
        ]);

        return $pos;
    }

    /**
     * Periodic position report from player.js. Only updates a row created at
     * play time; a finished episode drops its row so it leaves Continue
     * Listening and replays from the start.
     */
    public function saveProgress(string $episodeId, int $positionMs, ?int $durationMs): void
    {
        $row = PodcastProgress::where('episode_id', $episodeId)
            ->andWhere('client_id', client()->id)->first();
        if (!$row) {
            return;
        }

        $durationSec = $durationMs !== null
            ? (int) round($durationMs / 1000)
            : (int) $row->duration_sec;

        if ($this->isFinished($positionMs, $durationSec)) {
            $row->delete();
            return;
        }

        $row->update([
            'position_ms'  => max(0, $positionMs),
            'duration_sec' => $durationSec ?: null,
        ]);
    }

    /**
     * Drop an episode's resume row so it leaves Continue Listening.
     *
     * saveProgress() only ever updates an existing row, so dismissing an
     * episode that is still playing stays dismissed for the rest of the
     * session instead of being re-created by the next position beacon.
     */
    public function dismissProgress(string $episodeId): void
    {
        PodcastProgress::where('episode_id', $episodeId)
            ->andWhere('client_id', client()->id)
            ->first()?->delete();
    }

    /**
     * Episodes the client is partway through, most recent first, for the
     * "Continue Listening" section. Rows under 30s in are noise, not progress.
     */
    public function getInProgress(int $limit = 12): array
    {
        $rows = db()->fetchAll(
            "SELECT episode_id, podcast_hash, podcast_title, episode_title,
                    image, duration_sec, position_ms
             FROM podcast_progress
             WHERE client_id = ? AND position_ms >= 30000
             ORDER BY COALESCE(updated_at, created_at) DESC
             LIMIT $limit",
            [client()->id],
        );

        return array_map(fn($row) => $this->mapProgress($row), $rows);
    }

    /**
     * Progress view-model shared by Continue Listening and the episode list.
     */
    private function mapProgress(array $row): array
    {
        $posSec = (int) floor(((int) $row['position_ms']) / 1000);
        $durSec = (int) ($row['duration_sec'] ?? 0);

        return [
            'episode_id'    => $row['episode_id'],
            'podcast_hash'  => $row['podcast_hash'],
            'podcast_title' => $row['podcast_title'] ?? '',
            'episode_title' => $row['episode_title'] ?? '',
            'image'         => $row['image'] ?: '/images/no-album-art.png',
            'position_ms'   => (int) $row['position_ms'],
            'percent'       => $durSec > 0 ? min(100, (int) round($posSec / $durSec * 100)) : 0,
            'remaining_min' => $durSec > $posSec ? (int) ceil(($durSec - $posSec) / 60) : 0,
        ];
    }

    /**
     * Within 5% or 30 seconds of the end counts as done — resuming into the
     * outro isn't worth a Continue Listening slot.
     */
    private function isFinished(int $positionMs, int $durationSec): bool
    {
        if ($durationSec <= 0) {
            return false;
        }
        $remaining = $durationSec - ($positionMs / 1000);
        return $remaining <= max(30, $durationSec * 0.05);
    }

    /**
     * Stamp saved progress onto a page of mapped episodes (single query),
     * so the episode list can show a resume bar per row.
     */
    private function markProgress(array $episodes): array
    {
        $ids = array_filter(array_column($episodes, 'id'));
        if (empty($ids)) {
            return $episodes;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = db()->fetchAll(
            "SELECT episode_id, podcast_hash, podcast_title, episode_title,
                    image, duration_sec, position_ms
             FROM podcast_progress
             WHERE client_id = ? AND position_ms > 0 AND episode_id IN ($placeholders)",
            array_merge([client()->id], array_values($ids)),
        );
        $byId = array_column($rows, null, 'episode_id');

        foreach ($episodes as &$e) {
            $progress = $byId[$e['id']] ?? null;
            $e['progress'] = $progress ? $this->mapProgress($progress) : null;
        }

        return $episodes;
    }

    /**
     * Normalise a ListenNotes podcast node (search results use *_original
     * fields, detail uses bare ones) into our flat view-model.
     */
    private function mapPodcast(array $r): array
    {
        return [
            'hash'            => $r['id'] ?? '',
            'title'           => $r['title_original'] ?? $r['title'] ?? 'Untitled',
            'publisher'       => $r['publisher_original'] ?? $r['publisher'] ?? '',
            'description'     => $r['description_original'] ?? $r['description'] ?? '',
            'image'           => $r['image'] ?? '/images/no-album-art.png',
            'thumbnail'       => $r['thumbnail'] ?? $r['image'] ?? '/images/no-album-art.png',
            'total_episodes'  => (int) ($r['total_episodes'] ?? 0),
            'listennotes_url' => $r['listennotes_url'] ?? null,
            'liked'           => false,
        ];
    }

    private function mapEpisode(array $e, array $podcast): array
    {
        return [
            'id'               => $e['id'] ?? '',
            'podcast_hash'     => $podcast['hash'] ?? '',
            'podcast_title'    => $podcast['title'] ?? '',
            'title'            => $e['title'] ?? 'Untitled episode',
            'description'      => $e['description'] ?? '',
            'audio'            => $e['audio'] ?? '',
            'audio_length_sec' => (int) ($e['audio_length_sec'] ?? 0),
            'pub_date_ms'      => (int) ($e['pub_date_ms'] ?? 0),
            // Pre-format for the views (keeps date/duration math out of Twig).
            'pub_date'         => !empty($e['pub_date_ms']) ? date('M j, Y', (int) ($e['pub_date_ms'] / 1000)) : '',
            'duration_min'     => !empty($e['audio_length_sec']) ? (int) floor($e['audio_length_sec'] / 60) : 0,
            'image'            => $e['thumbnail'] ?? $e['image'] ?? ($podcast['thumbnail'] ?? '/images/no-album-art.png'),
        ];
    }

    /**
     * Stamp the per-client `liked` flag onto a list of mapped podcasts using a
     * single query (avoids N lookups against the snapshot table).
     */
    private function markLiked(array $items): array
    {
        $hashes = array_filter(array_column($items, 'hash'));
        if (empty($hashes)) {
            return $items;
        }

        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $rows = db()->fetchAll(
            "SELECT p.hash
             FROM podcasts p
             JOIN podcast_likes pl ON pl.podcast_id = p.id
             WHERE pl.client_id = ? AND p.hash IN ($placeholders)",
            array_merge([client()->id], array_values($hashes)),
        );
        $liked = array_column($rows, 'hash');

        foreach ($items as &$item) {
            $item['liked'] = in_array($item['hash'], $liked, true);
        }

        return $items;
    }
}
