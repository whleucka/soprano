<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, PlayerService, MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

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
            "dominant"    => $this->coverArt->dominantColor($album->cover),
            "tracks"      => $this->music->albumTracks((int) $album->id),
        ]);
    }
}

