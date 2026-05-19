<?php

namespace App\Services\Soprano;

use App\Models\{TrackMeta,TrackPlay};

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

    public function recentlyPlayed(int $album_count = 20): array
    {
        $dt = new \DateTime("- 1 DAY");
        $recently_played = TrackPlay::where("created_at", ">", $dt->format("Y-m-d H:i:s"))
            ->orderBy("id", "DESC")
            ->get($album_count);
        return array_map(fn($item) => [
            "hash" => $item->track()->hash,
            "client" => $item->client()?->username,
            "title" => $item->track()->meta()->title,
            "artist" => $item->track()->meta()->artist,
            "album" => $item->track()->meta()->album,
            "cover" => $item->track()->meta()->cover,
            "year" => $item->track()->meta()->year,
        ], $recently_played);
    }
}
