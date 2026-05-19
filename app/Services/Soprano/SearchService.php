<?php

namespace App\Services\Soprano;

use App\Models\TrackMeta;

class SearchService
{
    public function setSearch(string $term)
    {
        if ($term) {
            session()->set("search", [
                "tracks" => $this->search($term),
                "term" => $term,
            ]);
        } else {
            session()->set("search", [
                "tracks" => [],
                "term" => '',
            ]);
        }
    }

    public function search(string $term): array
    {
        $meta = new TrackMeta();
        $tracks = $meta->whereRaw("artist LIKE ? OR album LIKE ? OR artist LIKE ?", [
            "%$term%",
            "%$term%",
            "%$term%",
        ])->get();
        return array_map(fn($item) => [
            "hash" => $item->track()->hash,
            "title" => $item->title,
            "artist" => $item->artist,
            "album" => $item->album,
            "cover" => $item->cover,
            "year" => $item->year,
            "track_number" => $item->track_number,
            "playtime_string" => $item->playtime_string,
        ], $tracks);
    }

    public function getSearch()
    {
        return session()->get("search");
    }
}
