<?php

namespace App\Http\Controllers\Welcome;

use App\Http\StreamResponse;
use App\Models\TrackPlay;
use App\Services\Soprano\{CoverArtService,PlayerService,PlaylistService,MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class MusicController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private PlaylistService $playlist,
        private MusicService $music,
        private CoverArtService $coverArt,
    ) {}

    #[Get("/music", "music.index")]
    public function index(): string
    {
        return $this->render("music/index.html.twig", []);
    }

    #[Get("/music/album/{hash}", "music.album")]
    public function album(string $hash): string
    {
        $track = $this->music->getTrack($hash);
        $player = $this->player->getPlayer();

        if ($track) {
            $cover = $track->meta()->cover;
            return $this->render("music/album/index.html.twig", [
                "player" => $player,
                "hash" => $track->hash,
                "cover" => $cover,
                "album" => $track->meta()->album,
                "artist" => $track->meta()->artist,
                "dominant" => $this->coverArt->dominantColor($cover),
                "tracks" => $this->music->albumTracks($track->meta()),
            ]);
        }

        return $this->pageNotFound();
    }

    #[Get("/music/artist/{hash}", "music.artist")]
    public function artist(string $hash): string
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            return $this->render("music/artist/index.html.twig", [
                "hash" => $track->hash,
                "artist" => $track->meta()->artist,
            ]);
        }

        return $this->pageNotFound();
    }

    #[Get("/music/stream/{hash}", "music.stream")]
    public function stream(string $hash): StreamResponse
    {
        $track = $this->music->getTrack($hash);

        if ($track && is_file($track->pathname) && is_readable($track->pathname)) {
            return new StreamResponse($track->pathname);
        }

        return $this->pageNotFound();
    }

    #[Get("/music/play/{hash}", "music.play")]
    public function play(string $hash)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $src = uri("music.stream", $track->hash);
            // Debug
            error_log(print_r([
                "Now Playing",
                $track->meta()->title, 
                $track->meta()->artist, 
                $track->meta()->cover, 
                $src
            ], true));
            // Record track play
            TrackPlay::create([
                "track_id" => $track->id,
                "client_id" => client()?->id,
            ]);
            $this->player->setPlayer($track->hash, $track->meta()->title, $track->meta()->artist, $track->meta()->album, $track->meta()->cover, $src);
            $this->hxTrigger("loadPlayer, recentlyPlayed");
            return;
        }
    }

    #[Get("/music/play-album-track/{hash}/{index}", "music.play-album-track")]
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

    #[Get("/music/play-playlist-track/{index}", "music.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->playlist->getPlaylist();
        if (!empty($playlist['tracks'])) {
            $this->playlist->setPlaylist($playlist['tracks'], $index);
            $this->hxTrigger("nowPlaying");
            return $this->play($playlist['tracks'][$index]['hash']);
        }
    }

    #[Get("/music/play-album/{hash}", "music.play-album")]
    public function playAlbum(string $hash)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $tracks = $this->music->albumTracks($track->meta());
            if ($tracks) {
                $this->playlist->setPlaylist($tracks);
                $this->hxTrigger("loadPlaylist");
                return $this->play($tracks[0]['hash']);
            }
        }
    }

    #[Get("/music/next-track", "music.next-track")]
    public function nextTrack()
    {
        $next_track = $this->playlist->changePlaylistTrack();

        if ($next_track) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next_track['hash']); 
        }
    }

    #[Get("/music/prev-track", "music.prev-track")]
    public function prevTrack()
    {
        $prev_track = $this->playlist->changePlaylistTrack(false);

        if ($prev_track) {
            $this->hxTrigger("nowPlaying");
            return $this->play($prev_track['hash']); 
        }
    }
}
