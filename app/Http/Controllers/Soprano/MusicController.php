<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, PlayerService, MusicService};
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
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return $this->pageNotFound();
        }

        $artist = $album->artist();

        return $this->render("music/album/index.html.twig", [
            "player"      => $this->player->getPlayer(),
            "album_hash"  => $album->hash,
            "artist_hash" => $artist?->hash,
            "year"        => $album->year,
            "cover"       => $album->cover,
            "album"       => $album->title,
            "artist"      => $artist?->name,
            "dominant"    => $this->coverArt->dominantColor($album->cover),
            "tracks"      => $this->music->albumTracks((int) $album->id),
        ]);
    }

    #[Get("/music/artist/{hash}", "music.artist")]
    public function artist(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("music/artist/index.html.twig", [
            "artist_hash" => $artist->hash,
            "artist"      => $artist->name,
        ]);
    }

    #[Get("/music/artist/{hash}/discography", "music.artist.discography")]
    public function discography(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("music/artist/discography.html.twig", [
            "tracks" => $this->music->discography((int) $artist->id),
        ]);
    }

    #[Get("/music/artist/{hash}/top-tracks", "music.artist.top-tracks")]
    public function topTracks(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("music/artist/top-tracks.html.twig", [
            "player" => $this->player->getPlayer(),
            "tracks" => $this->music->topTracksByArtist((int) $artist->id),
        ]);
    }
}
