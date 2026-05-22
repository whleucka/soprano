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
            ->load('track')
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

    public function discography(string $artist, int $limit = 20): array
    {
        $rows = TrackMeta::where("artist", $artist)
            ->select(["album", "MIN(id) as first_id", "MAX(year) as year"])
            ->groupBy("album")
            ->orderBy("year", "DESC")
            ->getRaw($limit);

        $firstIds = array_column($rows, 'first_id');
        $metas = TrackMeta::whereIn('id', $firstIds)
            ->load('track')
            ->get();

        $metasById = [];
        foreach ($metas as $meta) {
            $metasById[$meta->id] = $meta;
        }

        return array_map(function ($row) use ($metasById) {
            $meta = $metasById[$row['first_id']];
            $track = $meta->track();
            return [
                "hash" => $track->hash,
                "title" => $meta->album,
                "artist" => $meta->artist,
                "album" => $meta->album,
                "cover" => $meta->cover,
                "year" => $meta->year,
            ];
        }, $rows);
    }

    public function topTracksByArtist(string $artist, int $limit = 10): array
    {
        $metaRows = TrackMeta::where("artist", $artist)
            ->select(["track_id"])
            ->getRaw();
        $ids = array_column($metaRows, 'track_id');
        if (empty($ids)) {
            return [];
        }

        $rows = TrackPlay::whereIn("track_id", $ids)
            ->select(["track_id", "COUNT(*) as plays"])
            ->groupBy("track_id")
            ->orderBy("plays", "DESC")
            ->getRaw($limit);

        $trackIds = array_column($rows, 'track_id');
        $tracks = Track::whereIn('id', $trackIds)
            ->load('meta')
            ->get();

        $tracksById = [];
        foreach ($tracks as $track) {
            $tracksById[$track->id] = $track;
        }

        return array_map(function ($row) use ($tracksById) {
            $track = $tracksById[$row['track_id']];
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
