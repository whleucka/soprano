<?php

namespace App\Services\Soprano;

class SopranoService
{
    public function getPlayer()
    {
        return session()->get("player");
    }

    public function setPlayer(string $title, string $artist, string $cover, string $src)
    {
        session()->set("player", [
            "title" => $title,
            "artist" => $artist,
            "cover" => $cover,
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
            ? $playlist["index"] + 1 
            : $playlist["index"] - 1;
        $new_index = $mod_index % count($playlist["tracks"]);

        if (!isset($playlist["tracks"][$new_index])) return false;

        $playlist["index"] = $new_index;

        $this->setPlaylist($playlist["tracks"], $new_index);

        $next_track = $playlist["tracks"][$new_index];

        return $next_track;
    }
}
