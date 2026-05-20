<?php

namespace App\Services\Soprano;

use App\Models\{Track, TrackMeta, TrackPlay};

class MusicService
{
    public function getTrack(string $hash)
    {
        return Track::where("hash", $hash)->first();
    }

    public function albumTracks(TrackMeta $track)
    {
        $tracks = TrackMeta::where("album", $track->album)
            ->andWhere("artist", $track->artist)
            ->get();
        // Sort numerically
        usort($tracks, fn($a, $b) => (int)$a->track_number <=> (int)$b->track_number);
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

    public function trackPlay(int $track_id, ?int $client_id)
    {
        // Record track play
        TrackPlay::create([
            "track_id" => $track_id,
            "client_id" => $client_id,
        ]);
    }
}
