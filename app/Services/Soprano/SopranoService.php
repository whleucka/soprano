<?php

namespace App\Services\Soprano;

class SopranoService
{
    public function getPlayer()
    {
        return session()->get("player");
    }

    public function setPlayer(string $title, string $artist, string $src)
    {
        session()->set("player", [
            "title" => $title,
            "artist" => $artist,
            "src" => $src,
        ]);

    }

    public function getPlaylist()
    {
        return session()->get("playlist");
    }

    public function setPlaylist(array $tracks)
    {
        session()->set("playlist", [
            "index" => 0,
            "tracks" => $tracks,
        ]);
    }
}
