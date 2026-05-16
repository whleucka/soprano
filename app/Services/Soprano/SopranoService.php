<?php

namespace App\Services\Soprano;

class SopranoService
{
    public function getPlayer()
    {
        return session()->get("player") ?? [
            "hash" => '#',
            "title" => 'N/A',
            "artist" => 'N/A',
            "album" => 'N/A',
            "cover" => '/images/no-album-art.png',
            "src" => '#',
        ];
    }

    public function setPlayer(string $hash, string $title, string $artist, string $album, ?string $cover, string $src)
    {
        session()->set("player", [
            "hash" => $hash,
            "title" => $title,
            "artist" => $artist,
            "album" => $album,
            "cover" => $cover ?? '/images/no-album-art.png',
            "src" => $src,
        ]);

    }

    public function getPlaylist()
    {
        return session()->get("playlist");
    }

    public function setPlaylist(array $tracks, int $index = 0)
    {
        session()->set("playlist", [
            "tracks" => $tracks,
            "index" => $index,
        ]);
    }

    public function changePlaylistTrack($forward = true): array|false
    {
        $playlist = $this->getPlaylist();

        if (!$playlist || count($playlist["tracks"]) < 2) return false;

        $mod_index = $forward 
            ? intval($playlist["index"]) + 1 
            : intval($playlist["index"]) - 1;
        $new_index = $mod_index % count($playlist["tracks"]);

        if ($new_index < 0) {
            $new_index = count($playlist["tracks"]) - 1;
        }

        if (!isset($playlist["tracks"][$new_index])) return false;

        $this->setPlaylist($playlist["tracks"], $new_index);

        $next_track = $playlist["tracks"][$new_index];

        return $next_track;
    }
}
