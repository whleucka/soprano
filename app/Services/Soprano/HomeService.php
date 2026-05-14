<?php

namespace App\Services\Soprano;

use App\Models\TrackMeta;

class HomeService
{
    public function recentlyAdded(int $album_count = 20): array
    {
        $recently_added = TrackMeta::where("id", ">", 0)
            ->groupBy("album")
            ->orderBy("id", "DESC")
            ->get($album_count);
        return array_map(fn($item) => [
            "hash" => $item->track()->hash,
            "title" => $item->title,
            "artist" => $item->artist,
            "album" => $item->album,
            "cover" => $item->cover,
            "year" => $item->year,
        ], $recently_added);
    }
}
