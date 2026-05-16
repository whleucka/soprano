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
        $player = $this->soprano->getPlayer() ?? [];
        return $this->render("music/player/index.html.twig", $player);
    }
}
