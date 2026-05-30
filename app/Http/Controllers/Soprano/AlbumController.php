<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, PlayerService, MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class AlbumController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private MusicService $music,
        private CoverArtService $coverArt,
    ) {}

    #[Get("/album/{hash}", "album.index")]
    public function album(string $hash): string
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return $this->pageNotFound();
        }

        $artist = $album->artist();

        return $this->render("album/index.html.twig", [
            "player"      => $this->player->getPlayer(),
            "album_hash"  => $album->hash,
            "artist_hash" => $artist?->hash,
            "year"        => $album->year,
            "cover"       => $album->cover,
            "album"       => $album->title,
            "artist"      => $artist?->name,
            "dominant"    => $this->coverArt->hexToRgb($album->dominant_color),
        ]);
    }

    #[Get("/album/{hash}/actions", "album.actions")]
    public function actions(string $hash): string
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return $this->pageNotFound();
        }

        return $this->render("album/actions.html.twig", [
            "album_hash"  => $album->hash,
        ]);
    }

    #[Get("/album/{hash}/tracks", "album.tracks")]
    public function tracks(string $hash): string
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return $this->pageNotFound();
        }

        return $this->render("album/tracks.html.twig", [
            "album_hash"  => $album->hash,
            "player"      => $this->player->getPlayer(),
            "tracks" => $this->music->albumTracks((int) $album->id),
        ]);
    }
}

