<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{PlayerService, MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class ArtistController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private MusicService $music,
    ) {}

    #[Get("/artist/{hash}", "artist.index")]
    public function artist(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/index.html.twig", [
            "artist_hash" => $artist->hash,
            "artist"      => $artist->name,
        ]);
    }

    #[Get("/artist/{hash}/actions", "artist.actions")]
    public function actions(string $hash): string
    {
        return $this->render("artist/actions.html.twig", [
        ]);
    }

    #[Get("/artist/{hash}/discography", "artist.discography")]
    public function discography(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/discography.html.twig", [
            "tracks" => $this->music->discography((int) $artist->id),
        ]);
    }

    #[Get("/artist/{hash}/top-tracks", "artist.top-tracks")]
    public function topTracks(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/top-tracks.html.twig", [
            "player" => $this->player->getPlayer(),
            "tracks" => $this->music->topTracksByArtist((int) $artist->id),
        ]);
    }
}

