<?php

namespace App\Services\Soprano;

class PlaylistService
{
    private const DEFAULT_STATE = [
        "tracks" => [],
        "index" => 0,
        "shuffle" => false
    ];

    public function getPlaylist(): array
    {
        return session()->get("playlist") ?? self::DEFAULT_STATE;
    }

    public function setPlaylist(array $tracks, int $index = 0, bool $shuffle = false)
    {
        session()->set("playlist", [
            "tracks" => $tracks,
            "index" => $index,
            "shuffle" => $shuffle,
        ]);
    }

    public function changePlaylistTrack(array $playlist, $forward = true): array|false
    {
        $playlist_count = count($playlist["tracks"]);

        if (!$playlist || $playlist_count < 2) return false;

        if ($playlist["shuffle"]) {
            $new_index = rand(0, $playlist_count - 1);
        } else {
            $mod_index = $forward
                ? intval($playlist["index"]) + 1
                : intval($playlist["index"]) - 1;
            $new_index = $mod_index % $playlist_count;
        }

        if ($new_index < 0) {
            $new_index = $playlist_count - 1;
        }

        if (!isset($playlist["tracks"][$new_index])) return false;

        $this->setPlaylist($playlist["tracks"], $new_index, $playlist["shuffle"]);

        $next_track = $playlist["tracks"][$new_index];

        return $next_track;
    }
}
