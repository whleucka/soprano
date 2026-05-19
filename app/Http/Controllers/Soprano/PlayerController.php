<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\PlayerService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlayerController extends Controller
{
    public function __construct(private PlayerService $player) {}

    #[Get("/player/load", "player.load")]
    public function load()
    {
        $player = $this->player->getPlayer() ?? [];
        return $this->render("music/player/index.html.twig", $player);
    }
}
