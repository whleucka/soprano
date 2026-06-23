<?php

namespace App\Services\Soprano;

use ListenNotes\PodcastApi\Client;
use ListenNotes\PodcastApi\Exception\ListenApiException;

/**
 * Thin, isolated wrapper around the official ListenNotes podcast API client.
 *
 * ListenNotes is keyed and quota-limited (the FREE tier caps search at 30
 * results/query and has a monthly request budget), so every call is wrapped in
 * cache()->remember() and a try/catch. This is the ONLY place the third-party
 * client is touched — business logic lives in PodcastService.
 *
 * All methods return PHP arrays (the client returns JSON strings). On any API
 * error we log and degrade gracefully (null / empty array) rather than throw,
 * so a quota blip never takes down a page.
 */
class ListenNotesService
{
    private Client $client;

    public function __construct()
    {
        // An empty key transparently routes to the ListenNotes mock server.
        $this->client = new Client((string) config('soprano.listennotes_key'));
    }

    /**
     * Search podcasts. Pagination is offset-based (free tier: up to 30 total).
     *
     * @return array{results: array, total: int, next_offset: ?int, count: int}|null
     */
    public function search(string $query, int $offset = 0): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $key = 'ln:search:' . md5($query) . ':' . $offset;

        return cache()->remember($key, (int) config('soprano.listennotes_search_ttl'), function () use ($query, $offset) {
            return $this->call(fn() => $this->client->search([
                'q'      => $query,
                'type'   => 'podcast',
                'offset' => $offset,
                'sort_by_date' => 0,
            ]));
        });
    }

    /**
     * Fetch a single podcast plus a page of its episodes (newest first).
     * Episode pagination uses `next_episode_pub_date` (a unix-ms cursor).
     */
    public function getPodcast(string $id, ?int $nextEpisodePubDate = null): ?array
    {
        $key = 'ln:podcast:' . $id . ':' . ($nextEpisodePubDate ?? 'first');

        return cache()->remember($key, (int) config('soprano.listennotes_detail_ttl'), function () use ($id, $nextEpisodePubDate) {
            $options = ['id' => $id, 'sort' => 'recent_first'];
            if ($nextEpisodePubDate !== null) {
                $options['next_episode_pub_date'] = $nextEpisodePubDate;
            }
            return $this->call(fn() => $this->client->fetchPodcastById($options));
        });
    }

    /**
     * Fetch a single episode by id (includes the audio URL + parent podcast).
     * Used to resolve playback for an episode that may not be on the podcast's
     * first episode page.
     */
    public function getEpisode(string $id): ?array
    {
        $key = 'ln:episode:' . $id;

        return cache()->remember($key, (int) config('soprano.listennotes_detail_ttl'), function () use ($id) {
            return $this->call(fn() => $this->client->fetchEpisodeById(['id' => $id]));
        });
    }

    /**
     * A randomly selected episode (the "just listen" button). Deliberately not
     * cached — each call should surface a different episode.
     */
    public function justListen(): ?array
    {
        return $this->call(fn() => $this->client->justListen([]));
    }

    /**
     * Curated "best podcasts" listing, optionally scoped to a genre.
     */
    public function getBestPodcasts(int $page = 1, ?string $genreId = null): ?array
    {
        $key = 'ln:best:' . ($genreId ?? 'all') . ':' . $page;

        return cache()->remember($key, (int) config('soprano.listennotes_detail_ttl'), function () use ($page, $genreId) {
            $options = ['page' => $page];
            if ($genreId !== null) {
                $options['genre_id'] = $genreId;
            }
            return $this->call(fn() => $this->client->fetchBestPodcasts($options));
        });
    }

    /**
     * Genre list (very stable — cached for a week). Top-level genres only by
     * default, which is the right granularity for a filter control (~21 vs 150+).
     */
    public function getGenres(bool $topLevelOnly = true): ?array
    {
        $key = 'ln:genres:' . ($topLevelOnly ? 'top' : 'all');

        return cache()->remember($key, (int) config('soprano.listennotes_genres_ttl'), function () use ($topLevelOnly) {
            return $this->call(fn() => $this->client->fetchPodcastGenres($topLevelOnly ? ['top_level_only' => 1] : []));
        });
    }

    /**
     * Run a client call, decode the JSON response, and swallow API errors.
     */
    private function call(callable $request): ?array
    {
        try {
            $json = $request();
            $decoded = json_decode((string) $json, true);
            return is_array($decoded) ? $decoded : null;
        } catch (ListenApiException $e) {
            // RateLimit / Authentication / NotFound / etc. all extend this.
            logger()->channel('soprano')->warning('ListenNotes API error', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            logger()->channel('soprano')->error('ListenNotes request failed', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
