<?php

namespace App\Services\Soprano;

use App\Models\{Podcast, PodcastLike};

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

        return $podcast;
    }

    /**
     * Resolve a single episode (for playback). Goes straight to the episode
     * endpoint so it works regardless of which page the episode came from.
     */
    public function getEpisode(string $episodeId): ?array
    {
        $data = $this->api->getEpisode($episodeId);
        if (!$data || empty($data['id'])) {
            return null;
        }

        $podcastNode = $data['podcast'] ?? [];
        $podcast = [
            'hash'      => $podcastNode['id'] ?? '',
            'title'     => $podcastNode['title_original'] ?? $podcastNode['title'] ?? '',
            'thumbnail' => $podcastNode['thumbnail'] ?? $podcastNode['image'] ?? '/images/no-album-art.png',
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
