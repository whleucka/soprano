<?php

namespace App\Services\Soprano;

class PlayerService
{
    private const DEFAULT_STATE = [
        'hash'        => '#',
        'album_hash'  => '#',
        'artist_hash' => '#',
        'title'       => 'N/A',
        'artist'      => 'N/A',
        'album'       => 'N/A',
        'cover'       => '/images/no-album-art.png',
        'src'         => '#',
    ];

    public function getPlayer(): array
    {
        return session()->get('player') ?? self::DEFAULT_STATE;
    }

    public function setPlayer(array $state): void
    {
        session()->set('player', [
            'hash'        => $state['hash']        ?? '#',
            'album_hash'  => $state['album_hash']  ?? '#',
            'artist_hash' => $state['artist_hash'] ?? '#',
            'title'       => $state['title']       ?? 'N/A',
            'artist'      => $state['artist']      ?? 'N/A',
            'album'       => $state['album']       ?? 'N/A',
            'cover'       => $state['cover']       ?? '/images/no-album-art.png',
            'src'         => $state['src']         ?? '#',
        ]);
    }
}
