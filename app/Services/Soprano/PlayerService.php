<?php

namespace App\Services\Soprano;

class PlayerService
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
}
