<?php

namespace App\Http\Controllers\Welcome;

use App\Http\StreamResponse;
use App\Models\Track;
use App\Services\Soprano\CoverArtService;
use App\Services\Soprano\MusicService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class MusicController extends Controller
{
    public function __construct(
        private MusicService $music,
        private CoverArtService $coverArt,
    ) {}

    #[Get("/music/album/{hash}", "music.album")]
    public function album(string $hash): string
    {
        $track = Track::where("hash", $hash)->first();

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
        $track = Track::where("hash", $hash)->first();

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
        $track = Track::where("hash", $hash)->first();

        if (!$track || !is_file($track->pathname) || !is_readable($track->pathname)) {
            return $this->pageNotFound();
        }

        return new StreamResponse($track->pathname);
    }

    #[Get("/music/play/{hash}", "music.play")]
    public function play(string $hash)
    {
        $track = Track::where("hash", $hash)->first();

        if ($track) {
            session()->set("player", [
                "title" => $track->meta()->title,
                "artist" => $track->meta()->artist,
                "src" => uri("music.stream", $track->hash),
            ]);
            $this->hxTrigger("loadPlayer");
            return true;
        }

        return $this->pageNotFound();
    }
}
