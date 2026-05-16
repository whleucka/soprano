<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\SopranoService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlaylistController extends Controller
{
    public function __construct(private SopranoService $soprano) {}

    #[Get("/playlist", "playlist.index")]
    public function index(): string
    {
        $playlist = $this->soprano->getPlaylist();
        $player = $this->soprano->getPlayer();
        return $this->render("music/playlist/index.html.twig", [
            "player" => $player,
            "playlist" => $playlist, 
        ]);
    }
}

