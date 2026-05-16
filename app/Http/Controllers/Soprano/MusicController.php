<?php

namespace App\Http\Controllers\Welcome;

use App\Http\StreamResponse;
use App\Services\Soprano\{CoverArtService,SopranoService,MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class MusicController extends Controller
{
    public function __construct(
        private SopranoService $soprano,
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

        if ($track) {
            $cover = $track->meta()->cover;
            return $this->render("music/album/index.html.twig", [
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
            error_log(print_r([
                "Now Playing",
                $track->meta()->title, 
                $track->meta()->artist, 
                $track->meta()->cover, 
                $src
            ], true));
            $this->soprano->setPlayer($track->hash, $track->meta()->title, $track->meta()->artist, $track->meta()->album, $track->meta()->cover, $src);
            $this->hxTrigger("loadPlayer");
            return;
        }

        return $this->pageNotFound();
    }

    #[Get("/music/play-album-track/{hash}/{index}", "music.play-album-track")]
    public function playAlbumTrack(string $hash, int $index)
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            $tracks = $this->music->albumTracks($track->meta());
            if ($tracks) {
                $this->soprano->setPlaylist($tracks, $index);
                $this->hxTrigger("nowPlaying, playlistQueue");
                return $this->play($tracks[$index]['hash']);
            }
        }

        return $this->pageNotFound();
    }

    #[Get("/music/play-playlist-track/{index}", "music.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->soprano->getPlaylist();
        if (!empty($playlist['tracks'])) {
            $this->soprano->setPlaylist($playlist['tracks'], $index);
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
                $this->soprano->setPlaylist($tracks);
                $this->hxTrigger("loadPlaylist");
                return $this->play($tracks[0]['hash']);
            }
        }

        return $this->pageNotFound();
    }

    #[Get("/music/next-track", "music.next-track")]
    public function nextTrack()
    {
        $next_track = $this->soprano->changePlaylistTrack();

        if ($next_track) {
            return $this->play($next_track['hash']); 
        }
    }

    #[Get("/music/prev-track", "music.prev-track")]
    public function prevTrack()
    {
        $prev_track = $this->soprano->changePlaylistTrack(false);

        if ($prev_track) {
            return $this->play($prev_track['hash']); 
        }
    }
}
