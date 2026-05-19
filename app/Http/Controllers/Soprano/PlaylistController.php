<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\{PlayerService,PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlaylistController extends Controller
{
    public function __construct(
        private PlaylistService $playlist, 
        private PlayerService $player
    ) {}

    #[Get("/playlist", "playlist.index")]
    public function index(): string
    {
        return $this->render("music/playlist/index.html.twig");
    }

    #[Get("/playlist/now-playing", "playlist.now-playing")]
    public function nowPlaying(): string
    {
        $player = $this->player->getPlayer();
        return $this->render("music/playlist/now-playing.html.twig", [
            "player" => $player,
        ]);
    }

    #[Get("/playlist/queue", "playlist.queue")]
    public function queue(): string
    {
        $playlist = $this->playlist->getPlaylist();
        return $this->render("music/playlist/queue.html.twig", [
            "playlist" => $playlist, 
        ]);
    }
}

