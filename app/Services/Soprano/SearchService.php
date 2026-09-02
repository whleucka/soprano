<?php

namespace App\Services\Soprano;

/**
 * Session-backed search state. The actual track query lives in
 * MusicService::searchTracks so it shares the feed SELECT/row shape.
 */
class SearchService
{
    public function __construct(private MusicService $music) {}

    public function setSearch(string $term): void
    {
        state()->search = [
            'tracks' => $this->music->searchTracks($term),
            'term' => $term,
            'context' => null,
        ];
    }

    /**
     * $context labels the header for the non-term entry points (the home
     * rails' "More" links, genre, decade) as {label, icon, color, note}.
     * Without it every one of them rendered a bare "Search" hero and you
     * couldn't tell Rediscover from Top Played once you'd clicked through.
     */
    public function setSearchResults(array $tracks, ?array $context = null): void
    {
        state()->search = [
            'tracks' => $tracks,
            'context' => $context,
        ];
    }

    public function clearSearch(): void
    {
        state()->search = [
            'tracks' => [],
            'term' => "",
            'context' => null,
        ];
    }

    /**
     * The cached rows carry a liked flag from whenever the search ran, so a
     * like toggled afterwards would be dropped the next time the results are
     * re-rendered (a play, for instance, retriggers searchResults). Re-read
     * the liked state on the way out.
     */
    public function getSearch(): array
    {
        $search = state()->search ?? [];
        $search['tracks'] = $this->music->refreshLikedState($search['tracks'] ?? []);

        return $search;
    }
}
