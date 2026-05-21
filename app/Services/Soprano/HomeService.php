<?php

namespace App\Services\Soprano;

use App\Models\{Track,TrackMeta,TrackPlay};

class HomeService
{
    public function recentlyAdded(int $album_count = 50): array
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

    public function recentlyPlayed(int $track_count = 20): array
    {
        $dt = new \DateTime("- 1 DAY");
        $rows = TrackPlay::where("created_at", ">", $dt->format("Y-m-d H:i:s"))
            ->select(["track_id", "MAX(id) as last_play_id"])
            ->groupBy("track_id")
            ->orderBy("last_play_id", "DESC")
            ->getRaw($track_count);

        return array_map(function ($row) {
            $play = TrackPlay::find($row['last_play_id']);
            $track = $play->track();
            $meta = $track->meta();
            return [
                "hash" => $track->hash,
                "client" => $play->client()?->username,
                "title" => $meta->title,
                "artist" => $meta->artist,
                "album" => $meta->album,
                "cover" => $meta->cover,
                "year" => $meta->year,
            ];
        }, $rows);
    }

    public function topPlayed(int $track_count = 20): array
    {
        $rows = TrackPlay::where("id", ">", 0)
            ->select(["track_id", "COUNT(*) as plays"])
            ->groupBy("track_id")
            ->orderBy("plays", "DESC")
            ->getRaw($track_count);

        return array_map(function ($row) {
            $track = Track::find($row['track_id']);
            $meta = $track->meta();
            return [
                "hash" => $track->hash,
                "title" => $meta->title,
                "artist" => $meta->artist,
                "album" => $meta->album,
                "cover" => $meta->cover,
                "year" => $meta->year,
                "plays" => (int) $row['plays'],
            ];
        }, $rows);
    }
}
