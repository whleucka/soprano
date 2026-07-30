<?php

namespace App\Services\Soprano;

class PlayerService
{
    public function getPlayer(): array
    {
        return state()->player;
    }

    public function setPlayer(array $state): void
    {
        state()->player = [
            'type'        => $state['type']        ?? 'track',
            'hash'        => $state['hash']        ?? '#',
            'episode_id'  => $state['episode_id']  ?? null,
            'resume_ms'   => $state['resume_ms']   ?? 0,
            'album_hash'  => $state['album_hash']  ?? '#',
            'artist_hash' => $state['artist_hash'] ?? '#',
            'title'       => $state['title']       ?? 'N/A',
            'artist'      => $state['artist']      ?? 'N/A',
            'album'       => $state['album']       ?? 'N/A',
            'cover'       => $state['cover']       ?? '/images/no-album-art.png',
            'src'         => $state['src']         ?? '#',
            'gain'        => $state['gain']        ?? 0,
            // Client crossfade preference + whether a next track exists to fade
            // into (auto-advance only) — read by player.js as data attributes.
            'crossfade'   => $state['crossfade']   ?? false,
            'has_next'    => $state['has_next']    ?? false,
        ];
    }
}
