<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\SopranoService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlayerController extends Controller
{
    public function __construct(private SopranoService $soprano) {}

    #[Get("/player/load", "player.load")]
    public function load()
    {
        $player = $this->soprano->getPlayer();
        return $this->render("music/player/index.html.twig", [
            "title" => $player["title"] ?? 'N/A',
            "artist" => $player["artist"]->artist ?? 'N/A',
            "cover" => $player["cover"] ?? '/images/no-album-art.png',
            "src" => $player["src"] ?? '',
        ]);
    }
}
