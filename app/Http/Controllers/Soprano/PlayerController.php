<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{MusicService, PlaylistService, PlayerService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;
use App\Http\StreamResponse;

class PlayerController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private PlaylistService $playlist,
        private MusicService $music,
    ) {}

    #[Get("/player/load", "player.load")]
    public function load(): string
    {
        return $this->render("player/index.html.twig", $this->player->getPlayer());
    }

    #[Get("/player/next-track", "player.next-track")]
    public function nextTrack()
    {
        $next = $this->playlist->changePlaylistTrack();
        if ($next) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next['hash']);
        }
    }

    #[Get("/player/prev-track", "player.prev-track")]
    public function prevTrack()
    {
        $prev = $this->playlist->changePlaylistTrack(false);
        if ($prev) {
            $this->hxTrigger("nowPlaying");
            return $this->play($prev['hash']);
        }
    }

    #[Get("/player/play/album/{hash}/track/{index}", "player.play-album-track")]
    public function playAlbumTrack(string $hash, int $index)
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return;
        }
        $tracks = $this->music->albumTracks((int) $album->id);
        if (empty($tracks[$index])) {
            return;
        }
        $this->playlist->setPlaylist($tracks, $index);
        $this->hxTrigger("nowPlaying, playlistQueue");
        return $this->play($tracks[$index]['hash']);
    }

    #[Get("/player/play/playlist/{index}", "player.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->playlist->getPlaylist();
        if (!empty($playlist['tracks'][$index])) {
            $this->playlist->setPlaylist($playlist['tracks'], $index);
            $this->hxTrigger("nowPlaying");
            return $this->play($playlist['tracks'][$index]['hash']);
        }
    }

    #[Get("/player/play/album/{hash}", "player.play-album")]
    public function playAlbum(string $hash)
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return;
        }
        $tracks = $this->music->albumTracks((int) $album->id);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks);
        $this->hxTrigger("nowPlaying, playlistQueue");
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/{hash}", "player.play")]
    public function play(string $hash)
    {
        $track = $this->music->getTrack($hash);
        if (!$track) {
            return;
        }

        $album  = $track->album();
        $artist = $track->artist();
        $meta   = $track->meta();
        $src    = uri("player.stream", $track->hash);

        $this->music->trackPlay((int) $track->id, client()?->id);
        $this->player->setPlayer([
            'hash'        => $track->hash,
            'album_hash'  => $album?->hash  ?? '#',
            'artist_hash' => $artist?->hash ?? '#',
            'title'       => $meta?->title  ?? '',
            'artist'      => $artist?->name ?? '',
            'album'       => $album?->title ?? '',
            'cover'       => $album?->cover,
            'src'         => $src,
        ]);
        $this->hxTrigger("loadPlayer, recentlyPlayed, topPlayed, topTracks");
    }

    #[Get("/player/stream/{hash}", "player.stream")]
    public function stream(string $hash): StreamResponse
    {
        $track = $this->music->getTrack($hash);

        if ($track && is_file($track->pathname) && is_readable($track->pathname)) {
            return new StreamResponse($track->pathname);
        }

        return $this->pageNotFound();
    }
}
