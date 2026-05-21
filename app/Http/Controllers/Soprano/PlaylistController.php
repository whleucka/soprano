<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class PlaylistController extends Controller
{
    public function __construct(
        private PlaylistService $playlist,
    ) {}

    #[Get("/playlist", "playlist.index")]
    public function index(): string
    {
        return $this->render("playlist/index.html.twig");
    }

    #[Get("/playlist/now-playing", "playlist.now-playing")]
    public function nowPlaying(): string
    {
        $playlist = $this->playlist->getPlaylist();
        $index = $playlist["index"];
        return $this->render("playlist/now-playing.html.twig", [
            "current" => $playlist["tracks"][$index] ?? [
                "hash" => "#",
                "artist" => "N/A",
                "cover" => "/images/no-album-art.png",
                "album" => "N/A",
                "title" => "N/A",
            ],
            "playlist" => $playlist
        ]);
    }

    #[Get("/playlist/queue", "playlist.queue")]
    public function queue(): string
    {
        return $this->render("playlist/queue.html.twig", [
            "playlist" => $this->playlist->getPlaylist(),
        ]);
    }

    #[Get("/playlist/queue/clear", "playlist.queue-clear")]
    public function queue_clear(): void
    {
        $this->playlist->setPlaylist([], 0);
        $this->hxTrigger("nowPlaying, playlistQueue");
    }
}
