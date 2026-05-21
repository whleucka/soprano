<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService,PlayerService,MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class MusicController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private MusicService $music,
        private CoverArtService $coverArt,
    ) {}

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
                "year" => $track->meta()->year,
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

    #[Get("/music/artist/{hash}/discography", "music.artist.discography")]
    public function discography(string $hash): string
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            return $this->render("music/artist/discography.html.twig", [
                "tracks" => $this->music->discography($track->meta()->artist),
            ]);
        }

        return $this->pageNotFound();
    }

    #[Get("/music/artist/{hash}/top-tracks", "music.artist.top-tracks")]
    public function topTracks(string $hash): string
    {
        $track = $this->music->getTrack($hash);

        if ($track) {
            return $this->render("music/artist/top-tracks.html.twig", [
                "tracks" => $this->music->topTracksByArtist($track->meta()->artist),
            ]);
        }

        return $this->pageNotFound();
    }
}
