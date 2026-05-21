<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\{MusicService, PlaylistService,PlayerService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;
use App\Http\StreamResponse;

class PlayerController extends Controller
{
    public function __construct(
        private PlayerService $player, 
        private PlaylistService $playlist,
        private MusicService $music
    ) {}

    #[Get("/player/load", "player.load")]
    public function load()
    {
        $player = $this->player->getPlayer() ?? [];
        return $this->render("player/index.html.twig", $player);
    }

    #[Get("/player/next-track", "player.next-track")]
    public function nextTrack()
    {
        $next_track = $this->playlist->changePlaylistTrack();

        if ($next_track) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next_track['hash']); 
        }
    }

    #[Get("/player/prev-track", "player.prev-track")]
    public function prevTrack()
    {
        $prev_track = $this->playlist->changePlaylistTrack(false);

        if ($prev_track) {
            $this->hxTrigger("nowPlaying");
            return $this->play($prev_track['hash']); 
        }
    }

    #[Get("/player/play/album/{hash}/track/{index}", "player.play-album-track")]
    public function playAlbumTrack(string $hash, int $index)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $tracks = $this->music->albumTracks($track->meta());
            if ($tracks) {
                return $this->play($tracks[$index]['hash']);
            }
        }
    }

    #[Get("/player/play/playlist/{index}", "player.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->playlist->getPlaylist();
        if (!empty($playlist['tracks'])) {
            $this->playlist->setPlaylist($playlist['tracks'], $index);
            $this->hxTrigger("nowPlaying");
            return $this->play($playlist['tracks'][$index]['hash']);
        }
    }

    #[Get("/player/play/album/{hash}", "player.play-album")]
    public function playAlbum(string $hash)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $tracks = $this->music->albumTracks($track->meta());
            if ($tracks) {
                $this->playlist->setPlaylist($tracks);
                $this->hxTrigger("nowPlaying, playlistQueue");
                return $this->play($tracks[0]['hash']);
            }
        }
    }

    #[Get("/player/play/{hash}", "player.play")]
    public function play(string $hash)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $src = uri("player.stream", $track->hash);
            $this->music->trackPlay($track->id, client()?->id);
            $this->player->setPlayer($track->hash, $track->meta()->title, $track->meta()->artist, $track->meta()->album, $track->meta()->cover, $src);
            $this->hxTrigger("loadPlayer, recentlyPlayed, topPlayed, topTracks");
            return;
        }
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
