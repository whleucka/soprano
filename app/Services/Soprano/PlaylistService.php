<?php

namespace App\Services\Soprano;

class PlaylistService
{
    public function getPlaylist(): array
    {
        return session()->get("playlist") ?? ["tracks" => [], "index" => 0];
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
