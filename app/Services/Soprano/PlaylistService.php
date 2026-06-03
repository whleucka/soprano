<?php

namespace App\Services\Soprano;

class PlaylistService
{
    public function getPlaylist(): array
    {
        return state()->playlist;
    }

    public function setPlaylist(array $tracks, int $index = 0)
    {
        state()->playlist = [
            "tracks" => $tracks,
            "index" => $index,
        ];
    }

    public function clearPlaylist()
    {
        state()->playlist = [
            "tracks" => [],
            "index" => 0,
        ];
    }

    public function setPlaylistIndex(int $index = 0)
    {
        state()->playlist = [
            "index" => $index,
        ];
    }

    public function toggleShuffle()
    {
        $shuffle = state()->playlist['shuffle'];
        state()->playlist = [
            'shuffle' => !$shuffle,
        ];
    }

    public function changePlaylistTrack($forward = true): array|false
    {
        $playlist = state()->playlist;

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

        $this->setPlaylist($playlist["tracks"], $new_index);

        $next_track = $playlist["tracks"][$new_index];

        return $next_track;
    }
}
