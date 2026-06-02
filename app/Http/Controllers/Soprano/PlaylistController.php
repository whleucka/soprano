<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, MusicService, PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class PlaylistController extends Controller
{
    private const DEFAULT_CURRENT = [
        "hash"        => "#",
        "album_hash"  => "#",
        "artist_hash" => "#",
        "artist"      => "N/A",
        "cover"       => "/images/no-album-art.png",
        "album"       => "N/A",
        "title"       => "N/A",
    ];

    public function __construct(
        private PlaylistService $playlist,
        private MusicService $music,
        private CoverArtService $coverArt,
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
            "dominant" => (isset($playlist["tracks"][$index])
                ? $this->coverArt->hexToRgb($playlist["tracks"][$index]["dominant_color"] ?? null)
                : null) ?? [18,18,18],
            "current" => $playlist["tracks"][$index] ?? self::DEFAULT_CURRENT
        ]);
    }

    #[Get("/playlist/actions", "playlist.actions")]
    public function actions(): string
    {
        return $this->render("playlist/actions.html.twig", [
            "playlist" => $this->playlist->getPlaylist(),
        ]);
    }

    #[Get("/playlist/queue", "playlist.queue")]
    public function queue(): string
    {
        $playlist = $this->playlist->getPlaylist();
        $index = $playlist["index"];
        return $this->render("playlist/queue.html.twig", [
            "playlist" => $this->playlist->getPlaylist(),
            "current" => $playlist["tracks"][$index] ?? false
        ]);
    }

    #[Get("/playlist/queue/clear", "playlist.queue-clear")]
    public function queueClear(): void
    {
        $this->playlist->setPlaylist([], 0);
        $this->hxTrigger("loadPlaylist");
    }

    #[Get("/playlist/shuffle", "playlist.shuffle")]
    public function shuffle()
    {
        $playlist = $this->playlist->getPlaylist();
        $this->playlist->setPlaylist($playlist["tracks"], $playlist["index"], !$playlist["shuffle"]);
        $this->hxTrigger("playlistActions");
    }

    #[Get("/playlist/random", "playlist.random")]
    public function random()
    {
        $playlist = $this->playlist->getPlaylist();
        $tracks = $this->music->randomTracks();
        $this->playlist->setPlaylist($tracks, 0, false);
        $this->hxTrigger("nowPlaying, playlistActions, playlistQueue");
    }
}
