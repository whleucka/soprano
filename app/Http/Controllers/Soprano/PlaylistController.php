<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\{PlayerService,PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlaylistController extends Controller
{
    private array $player;
    private array $playlist;

    public function __construct(
        private PlaylistService $playlistService, 
    ) {
        $this->playlist = $this->playlistService->getPlaylist();
    }

    #[Get("/playlist", "playlist.index")]
    public function index(): string
    {
        return $this->render("playlist/index.html.twig");
    }

    #[Get("/playlist/now-playing", "playlist.now-playing")]
    public function nowPlaying(): string
    {
        $index = $this->playlist["index"];
        return $this->render("playlist/now-playing.html.twig", [
            "current" => $this->playlist["tracks"][$index] ?? [
                "hash" => "#",
                "artist" => "N/A",
                "cover" => "/images/no-album-art.png",
                "album" => "N/A",
                "title" => "N/A",
            ],
            "playlist" => $this->playlist
        ]);
    }

    #[Get("/playlist/queue", "playlist.queue")]
    public function queue(): string
    {
        return $this->render("playlist/queue.html.twig", [
            "playlist" => $this->playlist, 
        ]);
    }

    #[Get("/playlist/queue/clear", "playlist.queue-clear")]
    public function queue_clear(): void
    {
        $this->playlistService->setPlaylist([], 0);
        $this->hxTrigger("nowPlaying, playlistQueue");
    }
}

