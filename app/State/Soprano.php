<?php

namespace App\State;

use Echo\Framework\Support\SingletonTrait;

class Soprano
{
    use SingletonTrait;

    private array $data = [
        'search' => [
            'term' => '',
            'tracks' => []
        ],
        'playlist' => [
            'tracks' => [],
            'index' => 0,
            'shuffle' => false
        ],
        'player' => [
            'hash'        => '#',
            'album_hash'  => '#',
            'artist_hash' => '#',
            'title'       => 'N/A',
            'artist'      => 'N/A',
            'album'       => 'N/A',
            'cover'       => '/images/no-album-art.png',
            'src'         => '#',
        ]
    ];

    public function __construct()
    {
        $this->hydrate();
    }

    private function hydrate()
    { 
        foreach (['search', 'playlist', 'player'] as $key) {
            $data = session()->get($key);
            if (!empty($data)) {
                $this->data[$key] = $data;
            }
        }
    }

    public function __get($key)
    {
        if (!array_key_exists($key, $this->data)) throw new \Error("Soprano state key '$key' doesn't exist");
        return $this->data[$key];
    }

    public function __set(string $key, array $data)
    {
        if (!array_key_exists($key, $this->data)) throw new \Error("Soprano state key '$key' doesn't exist");
        $this->data[$key] = array_merge($this->data[$key], $data);
        session()->set($key, $this->data[$key]);
    }
}
