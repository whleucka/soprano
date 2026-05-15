<?php

namespace App\Http\Controllers\Welcome;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlayerController extends Controller
{
    public function __construct()
    {
    }

    #[Get("/player/load", "player.load")]
    public function load()
    {
        $player = session()->get("player");
        return $this->render("music/player/index.html.twig", [
            "title" => $player["title"] ?? 'N/A',
            "artist" => $player["artist"]->artist ?? 'N/A',
            "cover" => $player["cover"] ?? '/images/no-album-art.png',
            "src" => $player["src"] ?? '',
        ]);
    }
}
