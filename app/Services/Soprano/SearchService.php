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
        ];
    }

    public function setSearchResults(array $tracks): void
    {
        state()->search = [
            'tracks' => $tracks,
        ];
    }

    public function clearSearch(): void
    {
        state()->search = [
            'tracks' => [],
            'term' => "",
        ];
    }

    public function getSearch(): array
    {
        return state()->search;
    }
}
