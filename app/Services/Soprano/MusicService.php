<?php

namespace App\Services\Soprano;

use App\Models\TrackMeta;

class MusicService
{
    public function albumTracks(TrackMeta $track)
    {
        $tracks = TrackMeta::where("album", $track->album)
            ->andWhere("artist", $track->artist)
            ->orderBy("track_number", "DESC")
            ->get();
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
}
