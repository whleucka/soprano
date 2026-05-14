<?php

namespace App\Http\Controllers\Welcome;

use App\Models\Track;
use App\Services\Soprano\MusicService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class MusicController extends Controller
{
    public function __construct(private MusicService $service) {}

    #[Get("/music/album/{hash}", "music.album")]
    public function album(string $hash)
    {
        $track = Track::where("hash", $hash)->first();

        if ($track) {
            return $this->render("music/album/index.html.twig", [
                "cover" => $track->meta()->cover,
                "album" => $track->meta()->album,
                "artist" => $track->meta()->artist,
                "tracks" => $this->service->albumTracks($track->meta()),
            ]);
        }

        return $this->pageNotFound();
    }
}
